<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Providers;

use DreamFactory\Core\AI\Providers\ToolCall;
use DreamFactory\Core\AI\Providers\ToolDefinition;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ToolCallingTest extends TestCase
{
    // ──────────────────────────────────────────────
    // ToolDefinition value object tests
    // ──────────────────────────────────────────────

    private function sampleTool(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_table_data',
            description: 'Retrieve rows from a table',
            parameters: [
                'type'       => 'object',
                'properties' => [
                    'tableName' => ['type' => 'string', 'description' => 'Table name'],
                    'limit'     => ['type' => 'integer', 'description' => 'Max rows'],
                ],
                'required' => ['tableName'],
            ],
        );
    }

    public function testToolDefinitionToAnthropic(): void
    {
        $tool = $this->sampleTool();
        $result = $tool->toAnthropic();

        $this->assertEquals('get_table_data', $result['name']);
        $this->assertEquals('Retrieve rows from a table', $result['description']);
        $this->assertArrayHasKey('input_schema', $result);
        $this->assertEquals('object', $result['input_schema']['type']);
    }

    public function testToolDefinitionToOpenAI(): void
    {
        $tool = $this->sampleTool();
        $result = $tool->toOpenAI();

        $this->assertEquals('function', $result['type']);
        $this->assertEquals('get_table_data', $result['function']['name']);
        $this->assertArrayHasKey('parameters', $result['function']);
        $this->assertEquals('object', $result['function']['parameters']['type']);
    }

    public function testToolDefinitionArrayConverters(): void
    {
        $tools = [$this->sampleTool()];

        $anthropic = ToolDefinition::toAnthropicArray($tools);
        $this->assertCount(1, $anthropic);
        $this->assertArrayHasKey('input_schema', $anthropic[0]);

        $openai = ToolDefinition::toOpenAIArray($tools);
        $this->assertCount(1, $openai);
        $this->assertEquals('function', $openai[0]['type']);
    }

    // ──────────────────────────────────────────────
    // ToolCall value object tests
    // ──────────────────────────────────────────────

    public function testToolCallFromAnthropic(): void
    {
        $block = [
            'type'  => 'tool_use',
            'id'    => 'toolu_abc123',
            'name'  => 'get_table_data',
            'input' => ['tableName' => 'customers', 'limit' => 10],
        ];

        $tc = ToolCall::fromAnthropic($block);

        $this->assertEquals('toolu_abc123', $tc->id);
        $this->assertEquals('get_table_data', $tc->name);
        $this->assertEquals(['tableName' => 'customers', 'limit' => 10], $tc->arguments);
    }

    public function testToolCallFromOpenAI(): void
    {
        $entry = [
            'id'       => 'call_xyz789',
            'type'     => 'function',
            'function' => [
                'name'      => 'get_table_data',
                'arguments' => '{"tableName":"orders","limit":5}',
            ],
        ];

        $tc = ToolCall::fromOpenAI($entry);

        $this->assertEquals('call_xyz789', $tc->id);
        $this->assertEquals('get_table_data', $tc->name);
        $this->assertEquals(['tableName' => 'orders', 'limit' => 5], $tc->arguments);
    }

    public function testToolCallFromOpenAIWithMalformedJSON(): void
    {
        $entry = [
            'id'       => 'call_bad',
            'function' => [
                'name'      => 'test',
                'arguments' => 'not json',
            ],
        ];

        $tc = ToolCall::fromOpenAI($entry);

        $this->assertEquals('call_bad', $tc->id);
        $this->assertEquals([], $tc->arguments);
    }

    public function testToolCallToArray(): void
    {
        $tc = new ToolCall('id1', 'tool_name', ['key' => 'val']);
        $arr = $tc->toArray();

        $this->assertEquals('id1', $arr['id']);
        $this->assertEquals('tool_name', $arr['name']);
        $this->assertEquals(['key' => 'val'], $arr['arguments']);
    }

    // ──────────────────────────────────────────────
    // AnthropicProvider chatWithTools tests
    // ──────────────────────────────────────────────

    private function createAnthropicProvider(MockHandler $mock): \DreamFactory\Core\AI\Providers\AnthropicProvider
    {
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $ref = new \ReflectionClass(\DreamFactory\Core\AI\Providers\AnthropicProvider::class);
        $provider = $ref->newInstanceWithoutConstructor();

        $refClient = $ref->getParentClass()->getProperty('client');
        $refClient->setValue($provider, $client);

        $refModel = $ref->getParentClass()->getProperty('defaultModel');
        $refModel->setValue($provider, 'claude-sonnet-4-5-20250929');

        $refMaxTokens = $ref->getParentClass()->getProperty('defaultMaxTokens');
        $refMaxTokens->setValue($provider, 4096);

        $refTemp = $ref->getParentClass()->getProperty('defaultTemperature');
        $refTemp->setValue($provider, 0.7);

        $refSys = $ref->getParentClass()->getProperty('systemPrompt');
        $refSys->setValue($provider, null);

        $refExtra = $ref->getParentClass()->getProperty('extraParams');
        $refExtra->setValue($provider, []);

        return $provider;
    }

    public function testAnthropicChatWithToolsReturnsToolCalls(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [
                    ['type' => 'text', 'text' => 'Let me look that up.'],
                    [
                        'type'  => 'tool_use',
                        'id'    => 'toolu_abc',
                        'name'  => 'get_table_data',
                        'input' => ['tableName' => 'customers'],
                    ],
                ],
                'model'       => 'claude-sonnet-4-5-20250929',
                'stop_reason' => 'tool_use',
                'usage'       => ['input_tokens' => 100, 'output_tokens' => 50],
            ])),
        ]);

        $provider = $this->createAnthropicProvider($mock);
        $result = $provider->chatWithTools(
            [['role' => 'user', 'content' => 'Show me customers']],
            [$this->sampleTool()],
        );

        $this->assertEquals('Let me look that up.', $result['content']);
        $this->assertNotNull($result['tool_calls']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertEquals('toolu_abc', $result['tool_calls'][0]['id']);
        $this->assertEquals('get_table_data', $result['tool_calls'][0]['name']);
        $this->assertEquals(['tableName' => 'customers'], $result['tool_calls'][0]['arguments']);
        $this->assertEquals('tool_use', $result['finish_reason']);
    }

    public function testAnthropicChatWithToolsTextOnly(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content'     => [['type' => 'text', 'text' => 'Here is your answer.']],
                'model'       => 'claude-sonnet-4-5-20250929',
                'stop_reason' => 'end_turn',
                'usage'       => ['input_tokens' => 80, 'output_tokens' => 30],
            ])),
        ]);

        $provider = $this->createAnthropicProvider($mock);
        $result = $provider->chatWithTools(
            [['role' => 'user', 'content' => 'Hello']],
            [$this->sampleTool()],
        );

        $this->assertEquals('Here is your answer.', $result['content']);
        $this->assertNull($result['tool_calls']);
        $this->assertEquals('end_turn', $result['finish_reason']);
    }

    // ──────────────────────────────────────────────
    // OpenAICompatibleProvider chatWithTools tests
    // ──────────────────────────────────────────────

    private function createOpenAIProvider(MockHandler $mock): \DreamFactory\Core\AI\Providers\OpenAICompatibleProvider
    {
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $ref = new \ReflectionClass(\DreamFactory\Core\AI\Providers\OpenAICompatibleProvider::class);
        $provider = $ref->newInstanceWithoutConstructor();

        $refClient = $ref->getParentClass()->getProperty('client');
        $refClient->setValue($provider, $client);

        $refModel = $ref->getParentClass()->getProperty('defaultModel');
        $refModel->setValue($provider, 'gpt-4o');

        $refMaxTokens = $ref->getParentClass()->getProperty('defaultMaxTokens');
        $refMaxTokens->setValue($provider, 4096);

        $refTemp = $ref->getParentClass()->getProperty('defaultTemperature');
        $refTemp->setValue($provider, 0.7);

        $refSys = $ref->getParentClass()->getProperty('systemPrompt');
        $refSys->setValue($provider, null);

        $refExtra = $ref->getParentClass()->getProperty('extraParams');
        $refExtra->setValue($provider, []);

        return $provider;
    }

    public function testOpenAIChatWithToolsReturnsToolCalls(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'role'       => 'assistant',
                            'content'    => null,
                            'tool_calls' => [
                                [
                                    'id'       => 'call_xyz',
                                    'type'     => 'function',
                                    'function' => [
                                        'name'      => 'get_table_data',
                                        'arguments' => '{"tableName":"orders"}',
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => 'tool_calls',
                    ],
                ],
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 40],
            ])),
        ]);

        $provider = $this->createOpenAIProvider($mock);
        $result = $provider->chatWithTools(
            [['role' => 'user', 'content' => 'Show me orders']],
            [$this->sampleTool()],
        );

        $this->assertNull($result['content']);
        $this->assertNotNull($result['tool_calls']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertEquals('call_xyz', $result['tool_calls'][0]['id']);
        $this->assertEquals('get_table_data', $result['tool_calls'][0]['name']);
        $this->assertEquals(['tableName' => 'orders'], $result['tool_calls'][0]['arguments']);
        $this->assertEquals('tool_calls', $result['finish_reason']);
    }

    public function testOpenAIChatWithToolsTextOnly(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message'       => ['role' => 'assistant', 'content' => 'Done.'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
            ])),
        ]);

        $provider = $this->createOpenAIProvider($mock);
        $result = $provider->chatWithTools(
            [['role' => 'user', 'content' => 'Thanks']],
            [$this->sampleTool()],
        );

        $this->assertEquals('Done.', $result['content']);
        $this->assertNull($result['tool_calls']);
        $this->assertEquals('stop', $result['finish_reason']);
    }

    // ──────────────────────────────────────────────
    // OllamaProvider chatWithTools tests
    // ──────────────────────────────────────────────

    private function createOllamaProvider(MockHandler $mock): \DreamFactory\Core\AI\Providers\OllamaProvider
    {
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $ref = new \ReflectionClass(\DreamFactory\Core\AI\Providers\OllamaProvider::class);
        $provider = $ref->newInstanceWithoutConstructor();

        $refClient = $ref->getParentClass()->getProperty('client');
        $refClient->setValue($provider, $client);

        $refModel = $ref->getParentClass()->getProperty('defaultModel');
        $refModel->setValue($provider, 'llama3.2');

        $refMaxTokens = $ref->getParentClass()->getProperty('defaultMaxTokens');
        $refMaxTokens->setValue($provider, 4096);

        $refTemp = $ref->getParentClass()->getProperty('defaultTemperature');
        $refTemp->setValue($provider, 0.7);

        $refSys = $ref->getParentClass()->getProperty('systemPrompt');
        $refSys->setValue($provider, null);

        $refExtra = $ref->getParentClass()->getProperty('extraParams');
        $refExtra->setValue($provider, []);

        return $provider;
    }

    public function testOllamaChatWithToolsReturnsToolCalls(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'message' => [
                    'role'       => 'assistant',
                    'content'    => '',
                    'tool_calls' => [
                        [
                            'id'       => 'ollama_call_1',
                            'type'     => 'function',
                            'function' => [
                                'name'      => 'get_table_data',
                                'arguments' => '{"tableName":"products"}',
                            ],
                        ],
                    ],
                ],
                'model'             => 'llama3.2',
                'done'              => false,
                'prompt_eval_count' => 60,
                'eval_count'        => 20,
            ])),
        ]);

        $provider = $this->createOllamaProvider($mock);
        $result = $provider->chatWithTools(
            [['role' => 'user', 'content' => 'List products']],
            [$this->sampleTool()],
        );

        $this->assertNotNull($result['tool_calls']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertEquals('get_table_data', $result['tool_calls'][0]['name']);
        $this->assertEquals(['tableName' => 'products'], $result['tool_calls'][0]['arguments']);
    }

    public function testOllamaChatWithToolsTextOnly(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'message' => [
                    'role'    => 'assistant',
                    'content' => 'Here are your results.',
                ],
                'model'             => 'llama3.2',
                'done'              => true,
                'prompt_eval_count' => 40,
                'eval_count'        => 15,
            ])),
        ]);

        $provider = $this->createOllamaProvider($mock);
        $result = $provider->chatWithTools(
            [['role' => 'user', 'content' => 'Summarize']],
            [$this->sampleTool()],
        );

        $this->assertEquals('Here are your results.', $result['content']);
        $this->assertNull($result['tool_calls']);
        $this->assertEquals('stop', $result['finish_reason']);
    }
}
