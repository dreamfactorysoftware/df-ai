<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use DreamFactory\Core\AI\Models\AiConnectionConfig;

/**
 * Resolves token rates and computes USD cost for an AI usage row.
 *
 * Lookup order, most specific first:
 *   1. Per-model entry in the AI Connection's model_rates JSON.
 *   2. Per-service flat rates on the AI Connection (cost_per_1k_input/output).
 *   3. DEFAULT_RATES table keyed by provider.
 *
 * Configs are cached in a static map for the lifetime of the request — the
 * usage logger fires once per provider call, so repeated lookups within a
 * batched request hit the cache.
 *
 * The DB-touching `estimate()` is a thin wrapper over the pure
 * `resolveRatesFromConfig()` / `costFor()` helpers below — those are the
 * unit-testable surface (see tests/Unit/Utility/UsageRatesTest.php).
 */
class UsageRates
{
    /** @var array<int, ?AiConnectionConfig> */
    private static array $configCache = [];

    /**
     * Provider-level fallback rates (USD per 1k tokens). Mirrors
     * df-admin-interface/src/app/adf-ai-usage/utils/cost.ts so the dashboard's
     * client-side what-if estimator and the server-stored cost agree when no
     * per-service rate is configured.
     *
     * @var array<string, array{input: float, output: float}>
     */
    public const DEFAULT_RATES = [
        'anthropic'         => ['input' => 0.003,  'output' => 0.015],
        'openai'            => ['input' => 0.0025, 'output' => 0.01],
        'xai'               => ['input' => 0.002,  'output' => 0.01],
        'ollama'            => ['input' => 0.0,    'output' => 0.0],
        'openai_compatible' => ['input' => 0.0,    'output' => 0.0],
    ];

    public static function estimate(
        int $serviceId,
        string $provider,
        ?string $model,
        int $inputTokens,
        int $outputTokens,
    ): float {
        [$in, $out] = self::resolveRatesFromConfig(
            self::loadConfig($serviceId),
            $provider,
            $model
        );
        return self::costFor($inputTokens, $outputTokens, $in, $out);
    }

    /**
     * Pure rate-resolution: given an optional config row and a request, walk
     * per-model → per-service flat → DEFAULT_RATES. Exposed for unit tests.
     *
     * @return array{0: float, 1: float} [inputPer1k, outputPer1k]
     */
    public static function resolveRatesFromConfig(
        ?AiConnectionConfig $config,
        string $provider,
        ?string $model,
    ): array {
        if ($config) {
            $modelRates = self::parseModelRates($config->model_rates ?? null);
            if ($model && isset($modelRates[$model])) {
                return $modelRates[$model];
            }

            $flatIn = $config->cost_per_1k_input ?? null;
            $flatOut = $config->cost_per_1k_output ?? null;
            if ($flatIn !== null || $flatOut !== null) {
                return [(float) ($flatIn ?? 0), (float) ($flatOut ?? 0)];
            }
        }

        $default = self::DEFAULT_RATES[$provider] ?? ['input' => 0.0, 'output' => 0.0];
        return [$default['input'], $default['output']];
    }

    /**
     * Pure cost math. Negative inputs are clamped to 0 — defensive against
     * upstream provider bugs that emit nonsensical token counts.
     */
    public static function costFor(
        int $inputTokens,
        int $outputTokens,
        float $inputPer1k,
        float $outputPer1k,
    ): float {
        $in = max(0, $inputTokens);
        $out = max(0, $outputTokens);
        return ($in / 1000) * $inputPer1k + ($out / 1000) * $outputPer1k;
    }

    /**
     * Decode the model_rates JSON column into a model-keyed lookup. Skips
     * malformed rows silently — strict validation lives in
     * AiConnectionConfig::saving so by the time we're reading, the column
     * is well-formed. This is a final defense for older rows.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    public static function parseModelRates(?string $raw): array
    {
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rates = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || empty($row['model'])) {
                continue;
            }
            $rates[$row['model']] = [
                (float) ($row['input_per_1k'] ?? 0),
                (float) ($row['output_per_1k'] ?? 0),
            ];
        }
        return $rates;
    }

    private static function loadConfig(int $serviceId): ?AiConnectionConfig
    {
        if (!array_key_exists($serviceId, self::$configCache)) {
            self::$configCache[$serviceId] = AiConnectionConfig::where('service_id', $serviceId)->first();
        }
        return self::$configCache[$serviceId];
    }

    /** Test/internal hook to drop the static cache (e.g. between integration runs). */
    public static function flushCache(): void
    {
        self::$configCache = [];
    }
}
