<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Providers\AiProviderInterface;
use DreamFactory\Core\AI\Providers\ToolDefinition;
use DreamFactory\Core\AI\Services\RateLimiter;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Utility\JWTUtilities;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Stateless "Chat with Your Data" resource on the AI Connection service.
 *
 * Each AI service is configured with a single App (API key). That app's
 * role determines which database services and tables the AI can access.
 *
 * GET  → returns config (role info, database services, tool support)
 * POST → runs an agentic tool-calling loop and returns the response
 */
class DataChatResource extends BaseRestResource
{
    public const RESOURCE_NAME = 'data-chat';

    private const MAX_TOOL_ITERATIONS = 25;

    /** Roles accepted from caller-supplied messages. */
    private static array $validRoles = ['system', 'user', 'assistant'];
    private const TOOL_RESULT_MAX_LENGTH = 50000;

    // ────────────────────────────────────────────────────────
    // GET — return configuration for the chat UI
    // ────────────────────────────────────────────────────────

    protected function handleGET(): array
    {
        $config = $this->getAiConfig();
        $app = $this->getConfiguredApp($config);

        $supportsToolUse = false;
        try {
            $provider = $this->getService()->getProvider();
            $supportsToolUse = method_exists($provider, 'chatWithTools');
        } catch (\Throwable $e) {
            // Provider couldn't be instantiated.
        }

        if (!$app) {
            return [
                'configured'       => false,
                'supportsToolUse'  => $supportsToolUse,
                'appName'          => null,
                'roleName'         => null,
                'databaseServices' => [],
            ];
        }

        $role = Role::find($app->role_id);
        $dbServices = $this->getDatabaseServicesForRole((int) $app->role_id);

        return [
            'configured'       => true,
            'supportsToolUse'  => $supportsToolUse,
            'appName'          => $app->name,
            'roleName'         => $role ? $role->name : "Role #{$app->role_id}",
            'databaseServices' => $dbServices,
        ];
    }

    // ────────────────────────────────────────────────────────
    // POST — run the agentic chat loop
    // ────────────────────────────────────────────────────────

    protected function handlePOST(): array
    {
        $payload = $this->getPayloadData();

        $messages = $payload['messages'] ?? [];
        if (empty($messages) || !is_array($messages)) {
            throw new BadRequestException('"messages" must be a non-empty array.');
        }

        // Validate every caller-supplied role against the allowlist before
        // any provider call. Without this, callers could inject extra
        // system-role messages mid-conversation, override the server-built
        // system prompt, or forge provider-internal roles like `tool` or
        // `developer` that may receive elevated trust from the model.
        foreach ($messages as $i => $msg) {
            $role = is_array($msg) ? ($msg['role'] ?? null) : null;
            if (!is_string($role) || !in_array($role, self::$validRoles, true)) {
                throw new BadRequestException(
                    "messages[{$i}].role must be one of: " . implode(', ', self::$validRoles) . '.'
                );
            }
        }

        // Resolve the single configured app.
        $config = $this->getAiConfig();
        $app = $this->getConfiguredApp($config);

        if (!$app) {
            throw new ForbiddenException(
                'No API key is configured for this AI service. '
                . 'An admin must select a Data Access API Key in the service configuration.'
            );
        }

        $roleId = (int) $app->role_id;
        $apiKey = $app->api_key;

        // Generate a token for data access under this role.
        $sessionToken = $this->generateTokenForRole($roleId, $app->id);

        // Get the AI provider.
        /** @var \DreamFactory\Core\AI\Services\AiConnection $service */
        $service = $this->getService();
        $provider = $service->getProvider();

        // Throttle BEFORE the agentic loop fans out — each request can issue
        // up to MAX_TOOL_ITERATIONS provider calls, so an unthrottled burst
        // amplifies provider cost by 25x.
        RateLimiter::check($service->getServiceId(), $provider->getProviderName());

        // Build tool definitions only for database services this role can access.
        $dbServices = $this->getDatabaseServicesForRole($roleId);

        if (empty($dbServices)) {
            throw new ForbiddenException(
                'The configured role has no access to any database services. '
                . 'Assign database service permissions to this role first.'
            );
        }

        $tools = $this->buildTools($dbServices);
        $systemPrompt = $this->buildSystemPrompt($dbServices);

        // Prepare messages for the provider.
        $providerMessages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($messages as $msg) {
            $providerMessages[] = [
                'role'    => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ];
        }

        // Run the agentic loop.
        $toolClient = $this->createToolClient($sessionToken, $apiKey);
        $start = hrtime(true);

        return $this->runAgenticLoop(
            $provider,
            $providerMessages,
            $tools,
            $toolClient,
            $dbServices,
            $start,
            $payload,
        );
    }

    // ────────────────────────────────────────────────────────
    // Agentic loop
    // ────────────────────────────────────────────────────────

    private function runAgenticLoop(
        AiProviderInterface $provider,
        array $messages,
        array $tools,
        array $toolClient,
        array $dbServices,
        int $startTime,
        array $payload,
    ): array {
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $toolCallsMade = [];
        $maxIterations = self::MAX_TOOL_ITERATIONS;

        $options = [];
        if (!empty($payload['model'])) {
            $options['model'] = $payload['model'];
        }
        if (isset($payload['maxTokens'])) {
            $options['max_tokens'] = (int) $payload['maxTokens'];
        }
        if (isset($payload['temperature'])) {
            $options['temperature'] = (float) $payload['temperature'];
        }

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            try {
                $result = $provider->chatWithTools($messages, $tools, $options);
            } catch (\LogicException $e) {
                // Provider doesn't support tool calling — fall back to regular chat.
                $result = [
                    'content'       => $provider->chat($messages, $options)['content'] ?? '',
                    'tool_calls'    => null,
                    'finish_reason' => 'stop',
                    'input_tokens'  => 0,
                    'output_tokens' => 0,
                ];
            }

            $totalInputTokens += $result['input_tokens'] ?? 0;
            $totalOutputTokens += $result['output_tokens'] ?? 0;

            // No tool calls — final response.
            if (empty($result['tool_calls'])) {
                $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

                return [
                    'content'        => $result['content'] ?? '',
                    'toolCallsMade'  => $toolCallsMade,
                    'messages'       => $messages,
                    'provider'       => $provider->getProviderName(),
                    'model'          => $result['model'] ?? '',
                    'inputTokens'    => $totalInputTokens,
                    'outputTokens'   => $totalOutputTokens,
                    'latencyMs'      => $latencyMs,
                    'iterations'     => $iteration + 1,
                ];
            }

            // AI wants to call tools — add assistant message.
            $messages[] = [
                'role'       => 'assistant',
                'content'    => $result['content'],
                'tool_calls' => $result['tool_calls'],
            ];

            // Execute each tool call.
            foreach ($result['tool_calls'] as $toolCall) {
                $toolStart = hrtime(true);
                $toolResult = $this->executeTool($toolClient, $toolCall, $dbServices);
                $toolDurationMs = (int) ((hrtime(true) - $toolStart) / 1_000_000);

                $toolCallsMade[] = [
                    'tool'          => $toolCall['name'] ?? '',
                    'input'         => $toolCall['arguments'] ?? [],
                    'outputPreview' => mb_substr($toolResult['content'], 0, 500),
                    'isError'       => $toolResult['is_error'],
                    'durationMs'    => $toolDurationMs,
                ];

                $messages[] = [
                    'role'         => 'tool',
                    'content'      => $toolResult['content'],
                    'tool_call_id' => $toolCall['id'] ?? '',
                ];
            }
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return [
            'content'        => 'I reached the maximum number of data queries without producing a final answer. Please try a simpler question.',
            'toolCallsMade'  => $toolCallsMade,
            'messages'       => $messages,
            'provider'       => $provider->getProviderName(),
            'model'          => '',
            'inputTokens'    => $totalInputTokens,
            'outputTokens'   => $totalOutputTokens,
            'latencyMs'      => $latencyMs,
            'iterations'     => $maxIterations,
        ];
    }

    // ────────────────────────────────────────────────────────
    // Tool execution
    // ────────────────────────────────────────────────────────

    private function executeTool(array $toolClient, array $toolCall, array $dbServices): array
    {
        $toolName = $toolCall['name'] ?? '';
        $args = $toolCall['arguments'] ?? [];

        [$serviceName, $action] = $this->parseToolName($toolName);

        if (!in_array($serviceName, $dbServices, true)) {
            return [
                'content'  => "Error: Service '{$serviceName}' is not available.",
                'is_error' => true,
            ];
        }

        try {
            $data = $this->callDreamFactoryApi($toolClient, $serviceName, $action, $args);
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (strlen($content) > self::TOOL_RESULT_MAX_LENGTH) {
                $content = substr($content, 0, self::TOOL_RESULT_MAX_LENGTH)
                    . "\n...[TRUNCATED. Use filter/limit to narrow results.]";
            }

            return ['content' => $content, 'is_error' => false];
        } catch (\Throwable $e) {
            Log::warning('DataChat tool error', [
                'tool'  => $toolName,
                'error' => $e->getMessage(),
            ]);

            return [
                'content'  => 'Tool error: ' . $e->getMessage(),
                'is_error' => true,
            ];
        }
    }

    private function callDreamFactoryApi(array $toolClient, string $service, string $action, array $args): array
    {
        /** @var Client $client */
        $client = $toolClient['client'];
        $baseUrl = $toolClient['baseUrl'];

        $uri = match ($action) {
            'get_tables'             => "/{$service}/_schema",
            'get_table_schema'       => "/{$service}/_schema/" . urlencode($args['tableName'] ?? ''),
            'get_table_data'         => "/{$service}/_table/" . urlencode($args['tableName'] ?? ''),
            'get_table_fields'       => "/{$service}/_schema/" . urlencode($args['tableName'] ?? '') . "/_field",
            'get_table_relationships'=> "/{$service}/_schema/" . urlencode($args['tableName'] ?? '') . "/_related",
            'get_stored_procedures'  => "/{$service}/_proc",
            'call_stored_procedure'  => "/{$service}/_proc/" . urlencode($args['procedureName'] ?? ''),
            'get_stored_functions'   => "/{$service}/_func",
            'call_stored_function'   => "/{$service}/_func/" . urlencode($args['functionName'] ?? ''),
            default                  => throw new \RuntimeException("Unknown tool action: {$action}"),
        };

        $options = [];

        if ($action === 'get_table_data') {
            $query = [];
            foreach (['fields', 'filter', 'limit', 'offset', 'order', 'group', 'related'] as $key) {
                if (isset($args[$key]) && $args[$key] !== '' && $args[$key] !== null) {
                    $query[$key] = is_array($args[$key]) ? implode(',', $args[$key]) : $args[$key];
                }
            }
            foreach (['include_count', 'count_only'] as $key) {
                if (!empty($args[$key])) {
                    $query[$key] = 'true';
                }
            }
            if (!empty($query)) {
                $options['query'] = $query;
            }
        }

        $method = 'GET';
        if (in_array($action, ['call_stored_procedure', 'call_stored_function'])) {
            $method = 'POST';
            $options['json'] = $args['parameters'] ?? [];
        }

        $response = $client->request($method, $baseUrl . $uri, $options);
        $body = json_decode($response->getBody()->getContents(), true);

        return is_array($body) ? $body : [];
    }

    // ────────────────────────────────────────────────────────
    // Tool definitions
    // ────────────────────────────────────────────────────────

    private function buildTools(array $dbServices): array
    {
        $tools = [];
        foreach ($dbServices as $svc) {
            $tools[] = new ToolDefinition(
                name: "{$svc}__get_tables",
                description: "List all tables available in the '{$svc}' database service.",
                parameters: ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            );
            $tools[] = new ToolDefinition(
                name: "{$svc}__get_table_schema",
                description: "Get the full schema (columns, types, keys) for a table in '{$svc}'.",
                parameters: [
                    'type'       => 'object',
                    'properties' => [
                        'tableName' => ['type' => 'string', 'description' => 'Table name'],
                    ],
                    'required' => ['tableName'],
                ],
            );
            $tools[] = new ToolDefinition(
                name: "{$svc}__get_table_data",
                description: "Query data from a table in '{$svc}'. Supports filtering, sorting, pagination, and field selection.",
                parameters: [
                    'type'       => 'object',
                    'properties' => [
                        'tableName'     => ['type' => 'string', 'description' => 'Table name'],
                        'fields'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Columns to return'],
                        'filter'        => ['type' => 'string', 'description' => 'SQL-style filter (e.g. "age > 30 AND city = \'NYC\')'],
                        'order'         => ['type' => 'string', 'description' => 'Sort order (e.g. "created_at DESC")'],
                        'limit'         => ['type' => 'integer', 'description' => 'Max rows to return (default 100)'],
                        'offset'        => ['type' => 'integer', 'description' => 'Rows to skip (for pagination)'],
                        'include_count' => ['type' => 'boolean', 'description' => 'Include total row count in response'],
                        'count_only'    => ['type' => 'boolean', 'description' => 'Return only the count, no data'],
                    ],
                    'required' => ['tableName'],
                ],
            );
            $tools[] = new ToolDefinition(
                name: "{$svc}__get_table_fields",
                description: "Get field definitions (column names, types, constraints) for a table in '{$svc}'.",
                parameters: [
                    'type'       => 'object',
                    'properties' => [
                        'tableName' => ['type' => 'string', 'description' => 'Table name'],
                    ],
                    'required' => ['tableName'],
                ],
            );
            $tools[] = new ToolDefinition(
                name: "{$svc}__get_table_relationships",
                description: "Get relationship definitions (foreign keys, related tables) for a table in '{$svc}'.",
                parameters: [
                    'type'       => 'object',
                    'properties' => [
                        'tableName' => ['type' => 'string', 'description' => 'Table name'],
                    ],
                    'required' => ['tableName'],
                ],
            );
        }
        return $tools;
    }

    // ────────────────────────────────────────────────────────
    // System prompt
    // ────────────────────────────────────────────────────────

    private function buildSystemPrompt(array $dbServices): string
    {
        $serviceList = implode(', ', $dbServices);

        return <<<PROMPT
You are a data assistant with access to DreamFactory database tools.

Available data services: {$serviceList}

Guidelines:
- Use get_tables and get_table_schema to understand the data structure before querying.
- Always use LIMIT (default 100) to avoid returning excessively large result sets.
- When presenting query results, format them clearly (tables, lists, or summaries).
- If you encounter an error from a tool, explain it to the user and suggest alternatives.
- Never access services not listed above.
PROMPT;
    }

    // ────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────

    private function getAiConfig(): ?AiConnectionConfig
    {
        return AiConnectionConfig::whereServiceId($this->getServiceId())->first();
    }

    /**
     * Get the single configured App from the AI service's app_id.
     */
    private function getConfiguredApp(?AiConnectionConfig $config): ?App
    {
        if (!$config || empty($config->app_id)) {
            return null;
        }

        return App::where('id', $config->app_id)
            ->where('is_active', true)
            ->first();
    }

    private function parseToolName(string $prefixed): array
    {
        $pos = strpos($prefixed, '__');
        if ($pos === false) {
            return ['', $prefixed];
        }
        return [substr($prefixed, 0, $pos), substr($prefixed, $pos + 2)];
    }

    private function generateTokenForRole(int $roleId, int $appId): string
    {
        // The JWT just needs to identify a valid user — the API key's role
        // (not the user's role) controls what data the AI can access.
        // Find or create a dedicated AI agent user for internal API calls.
        $user = User::where('email', 'ai-agent@system.local')->first();

        if (!$user) {
            // Auto-create the AI agent user on first use.
            $user = User::create([
                'name'         => 'AI Data Agent',
                'email'        => 'ai-agent@system.local',
                'password'     => \Hash::make(bin2hex(random_bytes(32))),
                'is_active'    => true,
                'is_sys_admin' => false,
            ]);
        }

        return JWTUtilities::makeJWTByUser($user->id, $user->email);
    }

    private function createToolClient(string $sessionToken, string $apiKey): array
    {
        // Use http://localhost for internal calls — avoids APP_URL port issues in containers.
        $baseUrl = 'http://localhost/api/v2';

        $client = new Client([
            'timeout' => 60,
            'headers' => [
                'Accept'                        => 'application/json',
                'Content-Type'                  => 'application/json',
                'X-DreamFactory-Session-Token'  => $sessionToken,
                'X-DreamFactory-API-Key'        => $apiKey,
            ],
        ]);

        return ['client' => $client, 'baseUrl' => $baseUrl];
    }

    /**
     * Get database service names that a specific role has GET access to.
     */
    private function getDatabaseServicesForRole(int $roleId): array
    {
        /** @var ServiceManager $sm */
        $sm = app('df.service');
        $dbNames = $sm->getServiceNamesByGroup(ServiceTypeGroups::DATABASE, true);

        if (empty($dbNames)) {
            return [];
        }

        // getServiceNamesByGroup returns sequential array of names, not keyed by ID.
        // Look up actual service IDs from the database.
        $dbServiceMap = []; // service_id => name
        foreach ($dbNames as $name) {
            $svc = \DB::table('service')->where('name', $name)->first();
            if ($svc) {
                $dbServiceMap[(int) $svc->id] = $name;
            }
        }

        $accessEntries = RoleServiceAccess::where('role_id', $roleId)->get();

        // Check for wildcard access (service_id = null/0 means all services).
        foreach ($accessEntries as $entry) {
            if (empty($entry->service_id) && ($entry->verb_mask & VerbsMask::GET_MASK)) {
                return array_values($dbServiceMap);
            }
        }

        $allowed = [];
        foreach ($accessEntries as $entry) {
            $sid = (int) $entry->service_id;
            if (isset($dbServiceMap[$sid]) && ($entry->verb_mask & VerbsMask::GET_MASK)) {
                $allowed[$sid] = $dbServiceMap[$sid];
            }
        }

        return array_values($allowed);
    }
}
