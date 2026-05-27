<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

/**
 * Redacts PII / PHI from prompts and AI responses before they're stored
 * in the audit log or shipped to SIEMs.
 *
 * **What this is:** a pre-storage scrub of obvious sensitive patterns
 * (SSN, credit card with Luhn validation, email, US phone, MRN-style
 * IDs). A fast, deterministic, regex-based first line of defense.
 *
 * **What this is NOT:** a substitute for cloud DLP (AWS Macie, Google
 * DLP, Microsoft Presidio, Skyflow, etc.) for buyers with strict
 * compliance requirements — those services do contextual NLP-based
 * detection across far more categories (passport numbers, driver's
 * license formats by state, IBANs, etc.). When a customer needs that
 * depth, they wire it in via the per-connection redaction rules
 * (config) or via a scripting hook around the prompt log write.
 *
 * The trade-off is deliberate: built-in regex patterns must have a
 * **near-zero false-positive rate** because admin retroactive review
 * is the alternative — a redactor that wrecks every legitimate
 * document gets disabled, defeating the point. We err on the side of
 * "miss some" rather than "redact every number." Specifically:
 *   - Credit cards are Luhn-validated (drops 90%+ of false matches)
 *   - SSN pattern requires the standard 3-2-4 dashed shape
 *   - Phone requires NANP shape with separators
 *   - Email is RFC-5322-ish but NOT the full grammar (lower FP)
 *
 * Custom patterns from `ai_connection_config.prompt_redaction_rules`
 * extend (but cannot weaken) the built-ins — admins can add IBAN /
 * passport / customer-id-format rules per connection.
 */
class PromptRedactor
{
    /** Replacement template for built-in patterns. Format: [REDACTED:{label}]. */
    public const PLACEHOLDER_TEMPLATE = '[REDACTED:%s]';

    /**
     * Built-in PII / PHI patterns. Order matters — patterns earlier in
     * the list match first, so put more-specific patterns ahead of
     * more-general ones (credit card before generic numbers).
     *
     * @var array<int, array{label: string, pattern: string, validator?: callable}>
     */
    private const BUILTIN_PATTERNS = [
        [
            'label'   => 'CREDIT_CARD',
            // 13-19 digits with optional separators every 4. Luhn-checked
            // post-match to drop false positives like timestamps.
            'pattern' => '/(?<![\d-])(\d[\d -]{11,21}\d)(?![\d-])/',
            'validator' => [self::class, 'isValidLuhn'],
        ],
        [
            'label'   => 'SSN',
            // US Social Security Number: 3-2-4 dashed. Excludes obviously
            // invalid prefixes (000, 666, 9xx) and groups (00) per SSA rules.
            'pattern' => '/(?<![\d-])(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}(?![\d-])/',
        ],
        [
            'label'   => 'EMAIL',
            // RFC-5322-ish (intentionally simplified to lower false positives).
            'pattern' => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        ],
        [
            'label'   => 'US_PHONE',
            // NANP shape: optional +1, area code in parens or with separator,
            // 3-4 digit groups separated by space/dash/dot. Must have
            // separators — bare 10 digits matches too many false positives
            // (timestamps, IDs).
            'pattern' => '/(?<![\d])(?:\+?1[-.\s]?)?\(?[2-9]\d{2}\)?[-.\s]\d{3}[-.\s]\d{4}(?!\d)/',
        ],
        [
            'label'   => 'MRN',
            // Hospital Medical Record Number: customers vary widely, but
            // the most common shape is "MRN" or "MRN:" or "Medical Record
            // Number:" followed by an alphanumeric ID. Matches that
            // explicit-keyword form rather than guessing at numeric IDs.
            'pattern' => '/(?:MRN|Medical[\s_-]?Record[\s_-]?(?:Number|No|#))[:\s#-]*([A-Z0-9-]{4,20})/i',
        ],
    ];

    /**
     * Apply redaction to a string.
     *
     * @param string $text The text to scrub
     * @param array{redact_pii?: bool, custom_rules?: array<int, array{pattern: string, label?: string, replacement?: string}>} $config
     *   - redact_pii: apply the built-in patterns (default true)
     *   - custom_rules: per-connection extra patterns
     * @return array{text: string, count: int}
     *   - text: the redacted string
     *   - count: total number of replacements made (for telemetry)
     */
    public static function redact(string $text, array $config = []): array
    {
        if ($text === '') {
            return ['text' => '', 'count' => 0];
        }

        $count = 0;
        $out = $text;

        // Built-in patterns (off only if explicitly disabled).
        if (($config['redact_pii'] ?? true) === true) {
            foreach (self::BUILTIN_PATTERNS as $rule) {
                $out = self::applyRule($out, $rule, $count);
            }
        }

        // Custom rules (per-connection).
        foreach ($config['custom_rules'] ?? [] as $rule) {
            if (empty($rule['pattern']) || !is_string($rule['pattern'])) {
                continue;
            }
            // Custom rules go through the same validator-aware path —
            // but custom rules don't have validators, so this just does
            // a straight regex replacement.
            $normalized = [
                'label'   => (string) ($rule['label'] ?? 'CUSTOM'),
                'pattern' => $rule['pattern'],
                'replacement' => $rule['replacement'] ?? null,
            ];
            $out = self::applyRule($out, $normalized, $count);
        }

        return ['text' => $out, 'count' => $count];
    }

    /**
     * Apply a single rule to the text. Bumps $count by the number of
     * replacements made. If the rule has a validator callable, only
     * matches that pass validation are redacted (Luhn for credit cards).
     *
     * @param array{label: string, pattern: string, validator?: callable, replacement?: ?string} $rule
     */
    private static function applyRule(string $text, array $rule, int &$count): string
    {
        $label = $rule['label'];
        $replacement = $rule['replacement']
            ?? sprintf(self::PLACEHOLDER_TEMPLATE, $label);

        if (isset($rule['validator']) && is_callable($rule['validator'])) {
            return preg_replace_callback(
                $rule['pattern'],
                function ($matches) use ($rule, $replacement, &$count) {
                    $candidate = $matches[1] ?? $matches[0];
                    if (call_user_func($rule['validator'], $candidate)) {
                        $count++;
                        return $replacement;
                    }
                    return $matches[0];
                },
                $text,
            );
        }

        $result = preg_replace_callback(
            $rule['pattern'],
            function () use ($replacement, &$count) {
                $count++;
                return $replacement;
            },
            $text,
        );
        return is_string($result) ? $result : $text;
    }

    /**
     * Luhn checksum — used to validate credit-card-shape matches before
     * redacting. Drops most false positives (long numeric IDs, etc.).
     * Pure / exposed for testing.
     */
    public static function isValidLuhn(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate);
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }

        $sum = 0;
        $alt = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    /**
     * Helper: parse a JSON array of redaction rules from the config
     * column into the shape expected by redact(). Defensive against
     * malformed config — silently drops invalid entries rather than
     * throwing (we'd rather store the prompt unredacted than not
     * store it at all because of a config typo, since the wire-level
     * audit happens regardless).
     *
     * @return array<int, array{pattern: string, label?: string, replacement?: string}>
     */
    public static function parseCustomRules(?string $json): array
    {
        if (!$json) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || empty($row['pattern']) || !is_string($row['pattern'])) {
                continue;
            }
            $out[] = [
                'pattern'     => $row['pattern'],
                'label'       => isset($row['label']) ? (string) $row['label'] : 'CUSTOM',
                'replacement' => isset($row['replacement']) ? (string) $row['replacement'] : null,
            ];
        }
        return $out;
    }
}
