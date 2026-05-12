<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Services;

use DreamFactory\Core\AI\Models\AiModelAlias;
use DreamFactory\Core\Exceptions\NotFoundException;

/**
 * Resolves logical model aliases to (service_id, physical_model) pairs
 * for the OpenAI-compatible gateway endpoint.
 *
 * **The contract:** customer apps send `model: "<alias>"` per the
 * OpenAI spec. We look up the alias, route the call to the configured
 * AI Connection, and pass the alias's physical_model to the upstream
 * provider. Aliases that don't exist or are inactive return a 404 —
 * we do NOT silently fall back to a default model, because that would
 * surprise customers who think they're hitting GPT-4o and actually
 * land on Qwen.
 *
 * The full alias list also drives the `/v1/models` endpoint that
 * OpenAI SDK clients call for autodiscovery. Inactive aliases are
 * excluded from that listing — admins can deprecate a model without
 * deleting the row (preserves audit trail).
 */
class ModelAliasResolver
{
    /**
     * Resolve a logical model name to its routing target.
     *
     * @return array{service_id: int, physical_model: string, alias_name: string}
     * @throws NotFoundException when no active alias matches
     */
    public static function resolve(string $logicalName): array
    {
        $alias = AiModelAlias::query()
            ->where('name', $logicalName)
            ->where('is_active', true)
            ->first();

        if (!$alias) {
            throw new NotFoundException(
                "Model '{$logicalName}' is not configured. "
                . "Available models: GET /api/v2/_ai/v1/models"
            );
        }

        return [
            'service_id'     => (int) $alias->service_id,
            'physical_model' => (string) $alias->physical_model,
            'alias_name'     => (string) $alias->name,
        ];
    }

    /**
     * List all active aliases in the OpenAI `/v1/models` shape.
     *
     * @return array<int, array{id: string, object: string, created: int, owned_by: string}>
     */
    public static function listForOpenAiModels(): array
    {
        $rows = AiModelAlias::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($rows as $alias) {
            $out[] = [
                'id'       => (string) $alias->name,
                'object'   => 'model',
                // ECMAScript-style epoch seconds. OpenAI fills this with
                // the model's release date; for us, the alias creation
                // time is the closest reasonable proxy.
                'created'  => $alias->created_at?->getTimestamp() ?? time(),
                'owned_by' => 'dreamfactory',
            ];
        }
        return $out;
    }
}
