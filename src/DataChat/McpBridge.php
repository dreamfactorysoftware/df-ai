<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\DataChat;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * PHP bridge to the MCP daemon for executing database tools.
 *
 * Communicates with the MCP daemon via JSON-RPC 2.0 over HTTP.
 * Each service gets its own MCP session, lazily initialized.
 */
class McpBridge
{
    private Client $client;
    private int $nextId = 1;

    /** @var array<string, string> service name → MCP session ID */
    private array $sessions = [];

    public function __construct(
        private readonly string $sessionToken,
        private readonly string $apiKey,
        private readonly string $mcpHost = 'http://127.0.0.1:8006',
        private readonly string $dfBaseUrl = 'http://localhost:8080',
    ) {
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Initialize an MCP session for a database service.
     */
    public function initSession(string $serviceName): void
    {
        if (isset($this->sessions[$serviceName])) {
            return;
        }

        $headers = [
            'X-DreamFactory-Session-Token' => $this->sessionToken,
            'X-DreamFactory-API-Key' => $this->apiKey,
            'X-Mcp-Config' => json_encode(['api_name' => $serviceName]),
            'X-Mcp-Base-Url' => rtrim($this->dfBaseUrl, '/') . '/api/v2/' . $serviceName,
        ];

        $response = $this->rawRequest($serviceName, 'initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'df-ai-datachat', 'version' => '1.0.0'],
        ], $headers);

        // Session ID is returned in the response headers.
        $sessionId = $response['sessionId'] ?? null;
        if (!$sessionId) {
            throw new RuntimeException("MCP daemon did not return a session ID for service '{$serviceName}'");
        }

        $this->sessions[$serviceName] = $sessionId;
    }

    /**
     * Get available tools from the MCP daemon for a service.
     *
     * @return array Tool definitions from MCP
     */
    public function getTools(string $serviceName): array
    {
        $this->initSession($serviceName);

        $response = $this->rpcCall($serviceName, 'tools/list', new \stdClass());

        return $response['result']['tools'] ?? [];
    }

    /**
     * Execute a tool via the MCP daemon.
     *
     * @return array Tool execution result
     */
    public function callTool(string $serviceName, string $toolName, array $arguments = []): array
    {
        $this->initSession($serviceName);

        $response = $this->rpcCall($serviceName, 'tools/call', [
            'name' => $toolName,
            'arguments' => empty($arguments) ? new \stdClass() : $arguments,
        ]);

        $result = $response['result'] ?? [];

        // Extract text content from MCP response format.
        $content = '';
        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'];
            }
        }

        return [
            'content' => $content,
            'is_error' => $result['isError'] ?? false,
        ];
    }

    /**
     * Close an MCP session for a service.
     */
    public function closeSession(string $serviceName): void
    {
        unset($this->sessions[$serviceName]);
    }

    /**
     * Close all MCP sessions.
     */
    public function closeAll(): void
    {
        $this->sessions = [];
    }

    /**
     * Send a JSON-RPC 2.0 request to the MCP daemon with session headers.
     */
    private function rpcCall(string $serviceName, string $method, mixed $params): array
    {
        if (!isset($this->sessions[$serviceName])) {
            throw new RuntimeException("No MCP session for service '{$serviceName}'. Call initSession() first.");
        }

        $headers = [
            'X-DreamFactory-Session-Token' => $this->sessionToken,
            'X-DreamFactory-API-Key' => $this->apiKey,
            'Mcp-Session-Id' => $this->sessions[$serviceName],
        ];

        return $this->rawRequest($serviceName, $method, $params, $headers);
    }

    /**
     * Low-level JSON-RPC request to MCP daemon.
     *
     * @return array{result?: array, error?: array, sessionId?: string}
     */
    private function rawRequest(string $serviceName, string $method, mixed $params, array $extraHeaders = []): array
    {
        $url = rtrim($this->mcpHost, '/') . '/mcp/' . urlencode($serviceName);

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $this->nextId++,
            'method' => $method,
            'params' => $params,
        ];

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $payload,
                'headers' => $extraHeaders,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!is_array($body)) {
                throw new RuntimeException('MCP daemon returned non-JSON response');
            }

            // Check for JSON-RPC error.
            if (isset($body['error'])) {
                throw new RuntimeException(
                    sprintf('MCP error (%d): %s', $body['error']['code'] ?? 0, $body['error']['message'] ?? 'Unknown'),
                );
            }

            // Extract session ID from response headers if present.
            $sessionId = $response->getHeaderLine('Mcp-Session-Id');
            if ($sessionId) {
                $body['sessionId'] = $sessionId;
            }

            return $body;
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                sprintf('MCP daemon request failed (%s %s): %s', $method, $serviceName, $e->getMessage()),
                (int) $e->getCode(),
                $e,
            );
        }
    }
}
