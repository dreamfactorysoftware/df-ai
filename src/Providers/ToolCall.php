<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

/**
 * Normalised representation of a tool call returned by an AI model.
 *
 * Each provider maps its own response format into this common structure
 * so that the orchestration layer can process tool calls uniformly.
 */
class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {}

    /**
     * Build from an Anthropic tool_use content block.
     *
     * @param array{id: string, name: string, input: array} $block
     */
    public static function fromAnthropic(array $block): self
    {
        return new self(
            id: $block['id'],
            name: $block['name'],
            arguments: $block['input'] ?? [],
        );
    }

    /**
     * Build from an OpenAI tool_calls entry.
     *
     * @param array{id: string, function: array{name: string, arguments: string}} $entry
     */
    public static function fromOpenAI(array $entry): self
    {
        $args = json_decode($entry['function']['arguments'] ?? '{}', true);

        return new self(
            id: $entry['id'],
            name: $entry['function']['name'],
            arguments: is_array($args) ? $args : [],
        );
    }

    /**
     * Serialize to the normalised array format used in responses.
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
