<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Utility;

use DreamFactory\Core\AI\Utility\PromptRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PromptRedactor — the pre-storage scrub that protects
 * regulated-industry buyers from accidentally persisting PII / PHI in
 * the prompt audit log.
 *
 * **The contract this class enforces:**
 *   1. Built-in patterns must redact common PII shapes accurately.
 *   2. False-positive rate must be near-zero — admins disable the
 *      redactor if it wrecks legitimate documents.
 *   3. The redaction count returned MUST match what the dashboard's
 *      "% of prompts with PII" analytic counts on. A drift here
 *      gives compliance officers wrong telemetry.
 *
 * Test coverage is deliberately heavy here: this is the pitch
 * differentiator for the regulated-industry customer; getting it wrong
 * (either over- or under-redacting) loses the deal in the security
 * review pen-test.
 */
class PromptRedactorTest extends TestCase
{
    // ─── Empty / no-op cases ──────────────────────────────────────────

    public function testEmptyStringIsHandled(): void
    {
        $r = PromptRedactor::redact('');
        $this->assertSame('', $r['text']);
        $this->assertSame(0, $r['count']);
    }

    public function testStringWithNoSensitiveDataIsUnchanged(): void
    {
        $clean = 'The patient was admitted on Tuesday. Vitals are stable.';
        $r = PromptRedactor::redact($clean);
        $this->assertSame($clean, $r['text']);
        $this->assertSame(0, $r['count']);
    }

    public function testRedactPiiCanBeDisabled(): void
    {
        $sensitive = 'My SSN is 123-45-6789';
        $r = PromptRedactor::redact($sensitive, ['redact_pii' => false]);
        $this->assertSame($sensitive, $r['text']);
        $this->assertSame(0, $r['count']);
    }

    // ─── SSN ──────────────────────────────────────────────────────────

    public function testSsnIsRedacted(): void
    {
        $r = PromptRedactor::redact('SSN: 123-45-6789');
        $this->assertStringContainsString('[REDACTED:SSN]', $r['text']);
        $this->assertStringNotContainsString('123-45-6789', $r['text']);
        $this->assertSame(1, $r['count']);
    }

    public function testInvalidSsnPrefixesAreNotRedacted(): void
    {
        // SSA never issues 000, 666, or 9xx prefixes — those are not real
        // SSNs, redacting them creates false positives in test/example data.
        $cases = ['000-12-3456', '666-12-3456', '900-12-3456'];
        foreach ($cases as $c) {
            $r = PromptRedactor::redact("Number: {$c}");
            $this->assertStringContainsString($c, $r['text'], "false positive on {$c}");
        }
    }

    public function testSsnWithInvalidGroupOrSerialNotRedacted(): void
    {
        // Group of 00 or serial of 0000 are SSA-invalid.
        $r = PromptRedactor::redact('Numbers: 123-00-1234 and 123-45-0000');
        $this->assertStringContainsString('123-00-1234', $r['text']);
        $this->assertStringContainsString('123-45-0000', $r['text']);
    }

    public function testMultipleSsnsAreAllRedacted(): void
    {
        $text = 'Patient A: 111-22-3333. Patient B: 222-33-4444.';
        $r = PromptRedactor::redact($text);
        $this->assertSame(2, $r['count']);
        $this->assertStringNotContainsString('111-22-3333', $r['text']);
        $this->assertStringNotContainsString('222-33-4444', $r['text']);
    }

    // ─── Credit Card (with Luhn) ──────────────────────────────────────

    public function testValidCreditCardIsRedacted(): void
    {
        // 4111111111111111 = standard Visa test number, passes Luhn.
        $r = PromptRedactor::redact('Card: 4111111111111111');
        $this->assertStringContainsString('[REDACTED:CREDIT_CARD]', $r['text']);
        $this->assertSame(1, $r['count']);
    }

    public function testCreditCardWithSpacesIsRedacted(): void
    {
        $r = PromptRedactor::redact('Card: 4111 1111 1111 1111');
        $this->assertStringContainsString('[REDACTED:CREDIT_CARD]', $r['text']);
    }

    public function testCreditCardWithDashesIsRedacted(): void
    {
        $r = PromptRedactor::redact('Card: 4111-1111-1111-1111');
        $this->assertStringContainsString('[REDACTED:CREDIT_CARD]', $r['text']);
    }

    public function testNonLuhnNumberIsNotRedactedAsCreditCard(): void
    {
        // 16-digit number that doesn't pass Luhn — common in production
        // logs (timestamps, hash IDs, etc.) and must NOT be redacted.
        $r = PromptRedactor::redact('Order id: 1234567812345678');
        $this->assertStringContainsString('1234567812345678', $r['text']);
        $this->assertSame(0, $r['count']);
    }

    public function testLuhnValidator(): void
    {
        // Public helper — pin known-good test cards.
        $this->assertTrue(PromptRedactor::isValidLuhn('4111111111111111')); // Visa test
        $this->assertTrue(PromptRedactor::isValidLuhn('5500000000000004')); // MasterCard test
        $this->assertTrue(PromptRedactor::isValidLuhn('340000000000009'));  // Amex test
        $this->assertFalse(PromptRedactor::isValidLuhn('1234567812345678')); // not Luhn
        $this->assertFalse(PromptRedactor::isValidLuhn('123'));              // too short
    }

    // ─── Email ────────────────────────────────────────────────────────

    public function testEmailIsRedacted(): void
    {
        $r = PromptRedactor::redact('Contact: john.doe@example.com');
        $this->assertStringContainsString('[REDACTED:EMAIL]', $r['text']);
        $this->assertStringNotContainsString('john.doe@example.com', $r['text']);
    }

    public function testEmailWithPlusAddressingIsRedacted(): void
    {
        $r = PromptRedactor::redact('alerts+billing@company.io');
        $this->assertStringContainsString('[REDACTED:EMAIL]', $r['text']);
    }

    public function testMultipleEmailsAllRedacted(): void
    {
        $r = PromptRedactor::redact('From: a@x.com To: b@y.org Cc: c@z.net');
        $this->assertSame(3, $r['count']);
    }

    // ─── US phone ─────────────────────────────────────────────────────

    public function testUsPhoneFormatsAreRedacted(): void
    {
        $cases = [
            'Call 555-867-5309 today',
            'Call (555) 867-5309 today',
            'Call 555.867.5309 today',
            'Call +1-555-867-5309 today',
        ];
        foreach ($cases as $c) {
            $r = PromptRedactor::redact($c);
            $this->assertStringContainsString('[REDACTED:US_PHONE]', $r['text'], "phone not redacted: {$c}");
        }
    }

    public function testBareTenDigitNumbersNotRedactedAsPhone(): void
    {
        // 10 contiguous digits (no separators) generates too many false
        // positives in real-world logs — our regex requires separators.
        $r = PromptRedactor::redact('Order timestamp 1234567890');
        $this->assertStringContainsString('1234567890', $r['text']);
    }

    // ─── MRN ──────────────────────────────────────────────────────────

    public function testMrnFormatsAreRedacted(): void
    {
        $cases = [
            'Patient MRN: ABC-12345 admitted',
            'MRN ABC12345 admitted',
            'Medical Record Number: PT-98765',
            'medical_record_no: 12345-XX',
        ];
        foreach ($cases as $c) {
            $r = PromptRedactor::redact($c);
            $this->assertStringContainsString('[REDACTED:MRN]', $r['text'], "MRN not redacted: {$c}");
        }
    }

    public function testStandaloneNumericIdIsNotRedactedAsMrn(): void
    {
        // The keyword "MRN" / "Medical Record" must precede the ID.
        // Pure numeric IDs without that context aren't redacted (would
        // false-positive on every order/customer ID).
        $r = PromptRedactor::redact('Order ID: 12345678');
        $this->assertStringContainsString('12345678', $r['text']);
    }

    // ─── Mixed payloads ───────────────────────────────────────────────

    public function testRealisticMedicalNoteRedactsAllPii(): void
    {
        $note = "Patient John Doe (SSN 123-45-6789, MRN: PT-9876). "
              . "Contact: john@example.com or 555-867-5309. "
              . "Card on file: 4111-1111-1111-1111.";

        $r = PromptRedactor::redact($note);

        // Five distinct PII tokens must all redact.
        $this->assertSame(5, $r['count']);
        $this->assertStringNotContainsString('123-45-6789', $r['text']);
        $this->assertStringNotContainsString('PT-9876', $r['text']);
        $this->assertStringNotContainsString('john@example.com', $r['text']);
        $this->assertStringNotContainsString('555-867-5309', $r['text']);
        $this->assertStringNotContainsString('4111', $r['text']);

        // Non-PII context survives.
        $this->assertStringContainsString('Patient John Doe', $r['text']);
    }

    // ─── Custom rules ─────────────────────────────────────────────────

    public function testCustomPatternIsApplied(): void
    {
        // Customer-specific ID format (e.g. internal employee ID).
        $r = PromptRedactor::redact(
            'Employee EMP-12345 logged in.',
            [
                'custom_rules' => [
                    ['pattern' => '/EMP-\d{5}/', 'label' => 'EMPLOYEE_ID'],
                ],
            ]
        );
        $this->assertStringContainsString('[REDACTED:EMPLOYEE_ID]', $r['text']);
        $this->assertStringNotContainsString('EMP-12345', $r['text']);
    }

    public function testCustomReplacementOverridesDefault(): void
    {
        $r = PromptRedactor::redact(
            'Product code XYZ-123',
            [
                'custom_rules' => [
                    ['pattern' => '/XYZ-\d+/', 'label' => 'SKU', 'replacement' => '###'],
                ],
            ]
        );
        $this->assertStringContainsString('###', $r['text']);
        $this->assertStringNotContainsString('XYZ-123', $r['text']);
        $this->assertStringNotContainsString('REDACTED', $r['text']);
    }

    public function testCustomRulesStackWithBuiltins(): void
    {
        $r = PromptRedactor::redact(
            'Email: a@b.com, Internal: ZZ-1',
            [
                'custom_rules' => [
                    ['pattern' => '/ZZ-\d/', 'label' => 'INTERNAL'],
                ],
            ]
        );
        $this->assertSame(2, $r['count']);
        $this->assertStringContainsString('[REDACTED:EMAIL]', $r['text']);
        $this->assertStringContainsString('[REDACTED:INTERNAL]', $r['text']);
    }

    public function testInvalidCustomRulesAreSilentlyDropped(): void
    {
        // A typo in the custom-rules JSON shouldn't kill the redactor —
        // we'd rather skip the bad rule than not redact the prompt at all.
        $r = PromptRedactor::redact(
            'SSN 123-45-6789',
            [
                'custom_rules' => [
                    ['no_pattern_field' => 'bad'],
                    ['pattern' => ''],
                    ['pattern' => null],
                    // A valid-but-unmatched pattern proves the rule is
                    // accepted; the malformed siblings above must NOT
                    // crash the call.
                    ['pattern' => '/zzz_no_match_zzz/', 'label' => 'NEVER'],
                ],
            ]
        );
        // SSN still redacts (built-in); malformed customs dropped silently.
        $this->assertStringContainsString('[REDACTED:SSN]', $r['text']);
        // The custom rule didn't match anything → no extra redaction.
        $this->assertStringNotContainsString('[REDACTED:NEVER]', $r['text']);
    }

    // ─── parseCustomRules ─────────────────────────────────────────────

    public function testParseCustomRulesAcceptsValidJson(): void
    {
        $json = json_encode([
            ['pattern' => '/ABC/', 'label' => 'A'],
            ['pattern' => '/XYZ/', 'label' => 'X', 'replacement' => '<X>'],
        ]);
        $rules = PromptRedactor::parseCustomRules($json);
        $this->assertCount(2, $rules);
        $this->assertSame('/ABC/', $rules[0]['pattern']);
        $this->assertSame('A', $rules[0]['label']);
        $this->assertSame('<X>', $rules[1]['replacement']);
    }

    public function testParseCustomRulesHandlesMalformedInput(): void
    {
        // Defensive: bad JSON should not throw.
        $this->assertSame([], PromptRedactor::parseCustomRules(null));
        $this->assertSame([], PromptRedactor::parseCustomRules(''));
        $this->assertSame([], PromptRedactor::parseCustomRules('not json'));
        $this->assertSame([], PromptRedactor::parseCustomRules('{}'));
        $this->assertSame([], PromptRedactor::parseCustomRules('"a string"'));
    }

    public function testParseCustomRulesSkipsRowsWithoutPattern(): void
    {
        $json = json_encode([
            ['label' => 'no-pattern'],
            ['pattern' => '/yes/', 'label' => 'kept'],
            ['pattern' => 123], // not a string — drop
        ]);
        $rules = PromptRedactor::parseCustomRules($json);
        $this->assertCount(1, $rules);
        $this->assertSame('/yes/', $rules[0]['pattern']);
    }
}
