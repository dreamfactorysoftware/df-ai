<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

/**
 * Provider-agnostic tool definition.
 *
 * Holds a tool's name, description, and JSON Schema parameters, with
 * converters for the different formats expected by each AI vendor.
 */
class ToolDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
    ) {}

    /**
     * Anthropic Messages API format.
     *
     * @return array{name: string, description: string, input_schema: array}
     */
    public function toAnthropic(): array
    {
        return [
            'name'         => $this->name,
            'description'  => $this->description,
            'input_schema' => $this->parameters,
        ];
    }

    /**
     * OpenAI Chat Completions / OpenAI-compatible format.
     *
     * @return array{type: string, function: array{name: string, description: string, parameters: array}}
     */
    public function toOpenAI(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->name,
                'description' => $this->description,
                'parameters'  => $this->parameters,
            ],
        ];
    }

    /**
     * Convert an array of ToolDefinitions to the Anthropic format.
     *
     * @param ToolDefinition[] $tools
     */
    public static function toAnthropicArray(array $tools): array
    {
        return array_map(fn(self $t) => $t->toAnthropic(), $tools);
    }

    /**
     * Convert an array of ToolDefinitions to the OpenAI format.
     *
     * @param ToolDefinition[] $tools
     */
    public static function toOpenAIArray(array $tools): array
    {
        return array_map(fn(self $t) => $t->toOpenAI(), $tools);
    }
}
