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
 *   3. MODEL_RATES table keyed by model name.
 *   4. DEFAULT_RATES table keyed by provider.
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
     * Provider-level fallback rates (USD per 1k tokens). Used when no
     * per-model or per-service rate is configured.
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

    /**
     * Per-model rates (USD per 1k tokens). Checked before DEFAULT_RATES
     * so models that differ from their provider average get correct cost.
     *
     * @var array<string, array{input: float, output: float}>
     */
    public const MODEL_RATES = [
        'claude-opus-4-8'            => ['input' => 0.015,   'output' => 0.075],
        'claude-opus-4-6'            => ['input' => 0.015,   'output' => 0.075],
        'claude-sonnet-4-6'          => ['input' => 0.003,   'output' => 0.015],
        'claude-sonnet-4-5-20250514' => ['input' => 0.003,   'output' => 0.015],
        'claude-3-5-sonnet-20241022' => ['input' => 0.003,   'output' => 0.015],
        'claude-haiku-4-5-20251001'  => ['input' => 0.001,   'output' => 0.005],
        'claude-3-5-haiku-20241022'  => ['input' => 0.001,   'output' => 0.005],
        'gpt-4o'                     => ['input' => 0.0025,  'output' => 0.01],
        'gpt-4o-2024-11-20'          => ['input' => 0.0025,  'output' => 0.01],
        'gpt-4.1'                    => ['input' => 0.002,   'output' => 0.008],
        'gpt-4.1-mini'               => ['input' => 0.0004,  'output' => 0.0016],
        'gpt-4.1-nano'               => ['input' => 0.0001,  'output' => 0.0004],
        'gpt-4o-mini'                => ['input' => 0.00015, 'output' => 0.0006],
        'gpt-4o-mini-2024-07-18'     => ['input' => 0.00015, 'output' => 0.0006],
        'grok-3'                     => ['input' => 0.003,   'output' => 0.015],
        'grok-3-mini'                => ['input' => 0.0003,  'output' => 0.0005],
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

        if ($model && isset(self::MODEL_RATES[$model])) {
            $mr = self::MODEL_RATES[$model];
            return [$mr['input'], $mr['output']];
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

    /**
     * Rates in API-friendly shape for the Gateway dashboard.
     *
     * @return array{providers: array<string, array{input_per_1k: float, output_per_1k: float}>, models: array<string, array{input_per_1k: float, output_per_1k: float}>}
     */
    public static function defaultRatesForApi(): array
    {
        $providers = [];
        foreach (self::DEFAULT_RATES as $provider => $rates) {
            $providers[$provider] = [
                'input_per_1k'  => $rates['input'],
                'output_per_1k' => $rates['output'],
            ];
        }

        $models = [];
        foreach (self::MODEL_RATES as $model => $rates) {
            $models[$model] = [
                'input_per_1k'  => $rates['input'],
                'output_per_1k' => $rates['output'],
            ];
        }

        return ['providers' => $providers, 'models' => $models];
    }
}
