<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\DataChat\DataChatToolBuilder;
use DreamFactory\Core\AI\DataChat\McpBridge;
use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\AI\Utility\UsageLogger;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseRestResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data Chat resource — agentic "chat with your data" via MCP tools.
 *
 * GET  /api/v2/{service}/data-chat  — discover API keys and capabilities
 * POST /api/v2/{service}/data-chat  — send a chat message with tool execution
 */
class DataChatResource extends BaseRestResource
{
    const RESOURCE_NAME = 'data-chat';

    protected function handleGET(): array
    {
        /** @var AiConnection $service */
        $service = $this->getService();
        $provider = $service->getProvider();

        $apiKeys = $this->resolveApiKeyLabels($service);
        $dbServices = $this->discoverDatabaseServices();

        return [
            'api_keys' => $apiKeys,
            'supports_tool_use' => $provider->supportsToolUse(),
            'database_services' => $dbServices,
        ];
    }

    protected function handlePOST(): array
    {
        $payload = $this->getPayloadData();
        $messages = $payload['messages'] ?? null;

        if (empty($messages) || !is_array($messages)) {
            throw new BadRequestException('"messages" array is required.');
        }

        /** @var AiConnection $service */
        $service = $this->getService();
        $provider = $service->getProvider();

        if (!$provider->supportsToolUse()) {
            throw new BadRequestException(
                sprintf('Provider "%s" does not support tool use required for data chat.', $provider->getProviderName())
            );
        }

        $config = config('df-ai.data_chat', []);
        $maxIterations = $config['max_iterations'] ?? 10;
        $maxResultChars = $config['max_result_chars'] ?? 50000;
        $mcpHost = $config['mcp_host'] ?? 'http://127.0.0.1:8006';

        // Resolve the API key to use.
        $apiKeyIndex = (int) ($payload['api_key_index'] ?? 0);
        $apiKey = $this->resolveApiKey($service, $apiKeyIndex);

        // Get the session token from the current request for MCP auth.
        $sessionToken = request()->header('X-DreamFactory-Session-Token')
            ?? request()->query('session_token', '');

        if (empty($sessionToken)) {
            throw new BadRequestException('Session token is required for data chat.');
        }

        // Auto-discover database services accessible via the API key.
        $dbServices = $this->discoverDatabaseServices();
        if (empty($dbServices)) {
            throw new BadRequestException('No database services found. Create a database service in DreamFactory first.');
        }

        $dfBaseUrl = config('app.url', 'http://localhost:8080');

        // Create MCP bridge and discover tools.
        $bridge = new McpBridge($sessionToken, $apiKey, $mcpHost, $dfBaseUrl);
        $toolBuilder = new DataChatToolBuilder($bridge, $dbServices);

        $start = hrtime(true);
        $toolCallsLog = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $iterations = 0;

        try {
            $buildResult = $toolBuilder->build();
            $tools = $buildResult['tools'];
            $serviceMap = $buildResult['service_map'];

            if (empty($tools)) {
                throw new InternalServerErrorException('No tools available from MCP daemon. Is it running?');
            }

            // Prepend system prompt with context.
            $systemPrompt = $this->buildSystemPrompt($dbServices);
            array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);

            $options = [
                'max_tokens' => (int) ($payload['max_tokens'] ?? $config['default_max_tokens'] ?? 4096),
                'temperature' => (float) ($payload['temperature'] ?? $config['default_temperature'] ?? 0.2),
            ];
            if (!empty($payload['model'])) {
                $options['model'] = $payload['model'];
            }

            // Agentic loop.
            $finalContent = null;
            for ($i = 0; $i < $maxIterations; $i++) {
                $iterations++;
                $response = $provider->chatWithTools($messages, $tools, $options);

                $totalInputTokens += $response['input_tokens'] ?? 0;
                $totalOutputTokens += $response['output_tokens'] ?? 0;

                // If no tool calls, we're done.
                if (empty($response['tool_calls'])) {
                    $finalContent = $response['content'];
                    break;
                }

                // Append the assistant's tool_use message to the conversation.
                $messages[] = $provider->buildAssistantToolCallMessage(
                    $response['content'],
                    $response['tool_calls'],
                );

                // Execute each tool call and append results.
                foreach ($response['tool_calls'] as $toolCall) {
                    $toolStart = hrtime(true);
                    $prefixedName = $toolCall['name'];
                    $isError = false;
                    $resultContent = '';

                    try {
                        [$targetService, $originalTool] = DataChatToolBuilder::resolveToolCall($prefixedName, $serviceMap);

                        if ($targetService === '_meta' && $originalTool === 'list_services') {
                            $resultContent = $toolBuilder->getServicesList();
                        } else {
                            $result = $bridge->callTool($targetService, $originalTool, $toolCall['input'] ?? []);
                            $resultContent = $result['content'] ?? '';
                            $isError = $result['is_error'] ?? false;
                        }

                        // Truncate large results.
                        if (strlen($resultContent) > $maxResultChars) {
                            $resultContent = substr($resultContent, 0, $maxResultChars)
                                . "\n\n[Result truncated at {$maxResultChars} characters]";
                        }
                    } catch (\Throwable $e) {
                        $resultContent = 'Error: ' . $e->getMessage();
                        $isError = true;
                    }

                    $toolDuration = (int) ((hrtime(true) - $toolStart) / 1_000_000);

                    $toolCallsLog[] = [
                        'tool' => $prefixedName,
                        'input' => $toolCall['input'] ?? [],
                        'output_preview' => substr($resultContent, 0, 500),
                        'is_error' => $isError,
                        'duration_ms' => $toolDuration,
                    ];

                    $messages[] = $provider->buildToolResultMessage(
                        $toolCall['id'],
                        $prefixedName,
                        $resultContent,
                        $isError,
                    );
                }
            }

            // If we hit max iterations without a final text response, note it.
            if ($finalContent === null) {
                $finalContent = '[Data chat reached maximum iterations without a final response. The AI may need a simpler question.]';
            }

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

            // Strip the system prompt we prepended before returning messages.
            $returnMessages = array_values(array_filter($messages, function ($msg, $idx) {
                return $idx > 0; // Skip the system prompt we added
            }, ARRAY_FILTER_USE_BOTH));

            $result = [
                'content' => $finalContent,
                'tool_calls_made' => $toolCallsLog,
                'messages' => $returnMessages,
                'provider' => $provider->getProviderName(),
                'model' => $options['model'] ?? $service->getConfig('default_model', ''),
                'input_tokens' => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
                'latency_ms' => $latencyMs,
                'iterations' => $iterations,
            ];

            UsageLogger::logSuccess($service->getServiceId(), self::RESOURCE_NAME, [
                'provider' => $result['provider'],
                'model' => $result['model'],
                'input_tokens' => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
            ], $latencyMs);

            return $result;
        } catch (\Throwable $e) {
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            UsageLogger::logError(
                $service->getServiceId(),
                self::RESOURCE_NAME,
                $provider->getProviderName(),
                $payload['model'] ?? $service->getConfig('default_model', ''),
                $latencyMs,
                $e->getMessage(),
            );
            throw $e;
        } finally {
            $bridge->closeAll();
        }
    }

    /**
     * Get the raw API key string from the service config by index.
     */
    private function resolveApiKey(AiConnection $service, int $index): string
    {
        $keysJson = $service->getConfig('data_chat_api_keys', '');
        $keys = [];

        if (!empty($keysJson)) {
            $keys = is_array($keysJson) ? $keysJson : (json_decode($keysJson, true) ?? []);
        }

        if (empty($keys)) {
            throw new BadRequestException('No API keys configured for data chat. Add API keys in the AI service config.');
        }

        if (!isset($keys[$index])) {
            throw new BadRequestException("Invalid api_key_index: {$index}. Available keys: 0-" . (count($keys) - 1));
        }

        return $keys[$index];
    }

    /**
     * Resolve API keys to their app/role labels for the GET response.
     */
    private function resolveApiKeyLabels(AiConnection $service): array
    {
        $keysJson = $service->getConfig('data_chat_api_keys', '');
        $keys = [];

        if (!empty($keysJson)) {
            $keys = is_array($keysJson) ? $keysJson : (json_decode($keysJson, true) ?? []);
        }

        $labels = [];
        foreach ($keys as $index => $apiKey) {
            $label = $this->lookupApiKeyLabel($apiKey);
            $labels[] = [
                'index' => $index,
                'app_name' => $label['app_name'],
                'role_name' => $label['role_name'],
            ];
        }

        return $labels;
    }

    /**
     * Look up the app name and role name for a DF API key.
     */
    private function lookupApiKeyLabel(string $apiKey): array
    {
        try {
            $app = DB::table('app')
                ->where('api_key', $apiKey)
                ->first(['name', 'role_id']);

            if (!$app) {
                return ['app_name' => '(unknown app)', 'role_name' => '(unknown role)'];
            }

            $roleName = '(no role)';
            if ($app->role_id) {
                $role = DB::table('role')->where('id', $app->role_id)->first(['name']);
                $roleName = $role->name ?? '(unknown role)';
            }

            return ['app_name' => $app->name, 'role_name' => $roleName];
        } catch (\Throwable $e) {
            Log::warning('DataChat: Failed to resolve API key label: ' . $e->getMessage());
            return ['app_name' => '(error)', 'role_name' => '(error)'];
        }
    }

    /** Database service types supported by MCP. */
    private const DB_SERVICE_TYPES = [
        'sqlite', 'mysql', 'pgsql', 'sqlsrv', 'oracle', 'ibmdb2', 'informix',
        'sqlanywhere', 'firebird', 'mongodb', 'cassandra', 'couchdb',
        'snowflake', 'bigquery', 'databricks', 'dremio', 'hana',
        'memsql', 'mysqldb', 'mariadb',
    ];

    /**
     * Discover database services available in this DreamFactory instance.
     *
     * @return string[] Service names
     */
    private function discoverDatabaseServices(): array
    {
        return DB::table('service')
            ->whereIn('type', self::DB_SERVICE_TYPES)
            ->where('is_active', true)
            ->pluck('name')
            ->toArray();
    }

    /**
     * Build the system prompt for data chat.
     */
    private function buildSystemPrompt(array $services): string
    {
        $serviceList = implode(', ', $services);
        $today = date('Y-m-d');

        return <<<PROMPT
You are a data analyst assistant. You help users query and understand their data using the available database tools.

Today's date: {$today}
Available database services: {$serviceList}

## Rules
1. Always check the schema (get_table_schema or get_tables) before querying data to understand column names and types.
2. Use filters to narrow results instead of fetching all data.
3. Never fabricate or assume data — only report what the tools return.
4. If a query returns an error, explain it clearly and suggest how to fix the question.
5. Use the limit parameter to avoid fetching too many rows (default to 100, max 1000).
6. When counting records, use the countOnly parameter instead of fetching all records.
7. Present results in clear, readable format (tables for structured data, summaries for aggregations).

## DreamFactory Filter Syntax
Filters use SQL-like syntax: `field operator value`
- Comparison: =, !=, >, >=, <, <=
- String: like, not like, starts with, ends with, contains
- Null checks: is null, is not null
- Logical: and, or
- In list: in (val1,val2,val3)

Examples:
- `age > 30`
- `status = 'active'`
- `name like '%smith%'`
- `created_at >= '2024-01-01' and status = 'active'`
- `department in ('sales','marketing')`
PROMPT;
    }

    protected function getApiDocPaths(): array
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);

        return [
            '/data-chat' => [
                'get' => [
                    'summary' => 'Get data chat configuration.',
                    'description' => 'Returns available API keys (as app/role labels) and tool use support.',
                    'operationId' => 'get' . $capitalized . 'DataChat',
                    'responses' => [
                        '200' => ['description' => 'Data chat configuration'],
                    ],
                ],
                'post' => [
                    'summary' => 'Send a data chat message.',
                    'description' => 'Send a question about your data. The AI will use database tools to find the answer.',
                    'operationId' => 'create' . $capitalized . 'DataChat',
                    'responses' => [
                        '200' => ['description' => 'Data chat response with tool calls and answer'],
                    ],
                ],
            ],
        ];
    }
}
