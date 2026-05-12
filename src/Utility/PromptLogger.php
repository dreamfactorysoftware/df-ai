<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Models\AiPromptLog;
use DreamFactory\Core\Utility\Session;
use Log;

/**
 * Writes prompt + response audit rows to `ai_prompt_log` when the
 * source AI Connection has prompt logging enabled.
 *
 * Architectural shape mirrors {@see UsageLogger}: static API, no
 * dependencies, exception-safe (write failures Log::warning rather
 * than killing the request — a logging failure should never break
 * the actual AI call).
 *
 * Per-connection toggles (read from AiConnectionConfig):
 *   prompt_logging_enabled    — master switch; default off
 *   prompt_redact_pii         — apply built-in patterns; default true
 *   prompt_redaction_rules    — JSON array of custom patterns
 *
 * Both request and response are redacted with the same ruleset before
 * storage. The `redaction_count` row column tallies replacements made
 * across both fields combined — useful for the dashboard's
 * "what % of traffic triggered a redaction" analytic without re-scanning
 * stored content.
 *
 * `request_id` correlates to the AiUsageLog row written for the same
 * AI call. Callers should pass the same UUID UsageLogger uses, then
 * dashboard queries can JOIN ai_usage_log + ai_prompt_log for full
 * forensics view.
 */
class PromptLogger
{
    /**
     * Cached configs by service_id so per-AI-Connection settings are
     * resolved once per request even if multiple AI calls fire in the
     * same HTTP request (orchestrator tool loops, multi-prompt flows).
     *
     * @var array<int, AiConnectionConfig|null>
     */
    private static array $configCache = [];

    /**
     * Record a prompt + response pair if the source connection opts in.
     *
     * @param int    $serviceId   AI Connection service id
     * @param string $resource    'chat' | 'completion' | 'chat-session' | etc.
     * @param string $provider    e.g. 'anthropic'
     * @param string $model       e.g. 'claude-haiku-4-5-20251001'
     * @param string $requestText Raw request payload (messages JSON-encoded
     *                            or prompt string — caller decides shape)
     * @param string $responseText Raw response content
     * @param string $requestId   UUID matching the ai_usage_log row
     * @param string $status      'success' | 'partial' | 'error'
     */
    public static function record(
        int $serviceId,
        string $resource,
        string $provider,
        string $model,
        string $requestText,
        string $responseText,
        string $requestId,
        string $status = 'success',
    ): void {
        if (!config('df-ai.prompt_logging.global_enabled', true)) {
            // Org-wide kill switch (env / config). Some deployments
            // legally cannot store prompts — admin sets this to false.
            return;
        }

        $config = self::loadConfig($serviceId);
        if (!$config || !($config->prompt_logging_enabled ?? false)) {
            return; // Per-connection opt-in; default off.
        }

        try {
            $redactConfig = [
                'redact_pii'   => (bool) ($config->prompt_redact_pii ?? true),
                'custom_rules' => PromptRedactor::parseCustomRules(
                    is_string($config->prompt_redaction_rules)
                        ? $config->prompt_redaction_rules
                        : (is_array($config->prompt_redaction_rules)
                            ? json_encode($config->prompt_redaction_rules)
                            : null)
                ),
            ];

            $req = PromptRedactor::redact($requestText, $redactConfig);
            $res = PromptRedactor::redact($responseText, $redactConfig);
            $totalRedactions = $req['count'] + $res['count'];

            AiPromptLog::create([
                'request_id'          => $requestId,
                'service_id'          => $serviceId,
                'user_id'             => Session::getCurrentUserId(),
                'role_id'             => Session::getRoleId(),
                'app_id'              => Session::get('app.id'),
                'provider'            => $provider,
                'model'               => $model,
                'resource'            => $resource,
                'request_payload'     => $req['text'],
                'response_payload'    => $res['text'],
                'redaction_count'     => $totalRedactions,
                'original_size_bytes' => strlen($requestText) + strlen($responseText),
                'status'              => $status,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record AI prompt log: ' . $e->getMessage(), [
                'service_id' => $serviceId,
                'request_id' => $requestId,
            ]);
        }
    }

    /**
     * Test/internal hook to drop the static cache (e.g. between integration runs).
     */
    public static function flushCache(): void
    {
        self::$configCache = [];
    }

    private static function loadConfig(int $serviceId): ?AiConnectionConfig
    {
        if (!array_key_exists($serviceId, self::$configCache)) {
            $cfg = AiConnectionConfig::whereServiceId($serviceId)->first();
            if ($cfg) {
                // Same protectedView trick AiProviderFactory uses — without
                // it, encrypted columns return masked values and we'd be
                // logging "**********" instead of the real prompts (worse:
                // we'd see masks even for the redaction config, which would
                // read as no-PII-redaction since prompt_redaction_rules
                // would render as the mask string).
                $cfg->protectedView = false;
            }
            self::$configCache[$serviceId] = $cfg;
        }
        return self::$configCache[$serviceId];
    }
}
