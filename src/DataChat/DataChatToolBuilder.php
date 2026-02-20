<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\DataChat;

/**
 * Builds a unified, read-only tool set from MCP tool definitions.
 *
 * Fetches tool definitions from the MCP daemon for each database service,
 * prefixes tool names with the service name for disambiguation, and filters
 * out write operations (create, update, delete).
 */
class DataChatToolBuilder
{
    /** Tools that perform write operations — excluded from data chat. */
    private const WRITE_TOOLS = [
        'create_records',
        'update_records',
        'delete_records',
    ];

    /** Tools that are stubs or not useful for data chat. */
    private const EXCLUDED_TOOLS = [
        'search',
        'fetch',
    ];

    private McpBridge $bridge;

    /** @var string[] Database service names */
    private array $services;

    public function __construct(McpBridge $bridge, array $services)
    {
        $this->bridge = $bridge;
        $this->services = $services;
    }

    /**
     * Build the complete tool set for the AI provider.
     *
     * Returns tools in the normalized format expected by AiProviderInterface::chatWithTools().
     * Each MCP tool is prefixed with the service name (e.g., "mydb__get_tables").
     * A synthetic "list_services" tool is added for multi-service contexts.
     *
     * @return array{tools: array, service_map: array<string, string>}
     *   tools: Tool definitions for the AI provider
     *   service_map: Maps prefixed tool name → [service, original_tool_name]
     */
    public function build(): array
    {
        $tools = [];
        $serviceMap = [];

        // Add list_services meta-tool when multiple services are available.
        if (count($this->services) > 1) {
            $tools[] = [
                'name' => 'list_services',
                'description' => 'List the available database services you can query. Call this first to see what databases are available.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ];
            $serviceMap['list_services'] = ['_meta', 'list_services'];
        }

        foreach ($this->services as $service) {
            $mcpTools = $this->bridge->getTools($service);

            foreach ($mcpTools as $mcpTool) {
                $toolName = $mcpTool['name'] ?? '';

                if ($this->isExcluded($toolName)) {
                    continue;
                }

                // Prefix with service name for disambiguation.
                $prefixedName = $this->prefixToolName($service, $toolName);

                $tools[] = [
                    'name' => $prefixedName,
                    'description' => $this->buildDescription($service, $mcpTool),
                    'input_schema' => $mcpTool['inputSchema'] ?? [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ];

                $serviceMap[$prefixedName] = [$service, $toolName];
            }
        }

        return [
            'tools' => $tools,
            'service_map' => $serviceMap,
        ];
    }

    /**
     * Resolve a prefixed tool name back to its service and original MCP tool name.
     */
    public static function resolveToolCall(string $prefixedName, array $serviceMap): array
    {
        if (!isset($serviceMap[$prefixedName])) {
            throw new \RuntimeException("Unknown tool: {$prefixedName}");
        }

        return $serviceMap[$prefixedName];
    }

    /**
     * Generate the list_services response content.
     */
    public function getServicesList(): string
    {
        $list = array_map(fn(string $s) => ['name' => $s], $this->services);
        return json_encode(['services' => $list]);
    }

    private function prefixToolName(string $service, string $toolName): string
    {
        // Use double underscore as separator (safe for all AI providers).
        return $service . '__' . $toolName;
    }

    private function buildDescription(string $service, array $mcpTool): string
    {
        $desc = $mcpTool['description'] ?? $mcpTool['name'] ?? '';
        if (count($this->services) > 1) {
            $desc = "[{$service}] " . $desc;
        }
        return $desc;
    }

    private function isExcluded(string $toolName): bool
    {
        return in_array($toolName, self::WRITE_TOOLS, true)
            || in_array($toolName, self::EXCLUDED_TOOLS, true);
    }
}
