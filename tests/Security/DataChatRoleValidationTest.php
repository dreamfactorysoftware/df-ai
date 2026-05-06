<?php

namespace DreamFactory\Core\AI\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: DataChatResource must validate the message `role` field.
 *
 * The April 2026 audit (df-ai F-03) found that DataChatResource passes
 * `$msg['role'] ?? 'user'` straight through to the provider without
 * checking it against an allowlist. The follow-up review found that even
 * allowing caller-supplied `system` messages is unsafe here because this
 * resource already prepends a trusted server-built system prompt carrying
 * the SQL/tool guardrails.
 *
 * Without strict validation a caller can:
 *  - Inject extra system-role messages mid-conversation, overriding the
 *    server-built system prompt and the SQL/tool guardrails it carries.
 *  - Forge provider-internal role tokens (e.g., `tool`, `developer`)
 *    that may receive elevated trust from the model or change billing
 *    accounting on the provider side.
 *
 * After the fix, DataChatResource rejects any caller message whose `role`
 * is not in the strict allowlist `['user', 'assistant']` before any
 * provider call.
 */
class DataChatRoleValidationTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Resources/DataChatResource.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testSourceHasRoleAllowlist(): void
    {
        // The fix should declare a strict allowlist containing only the
        // caller-safe roles `user` and `assistant`.
        $hasStrictAllowlist =
            preg_match('/[\'"]user[\'"]\s*,\s*[\'"]assistant[\'"]/', $this->contents) === 1;

        $this->assertTrue(
            $hasStrictAllowlist,
            'DataChatResource must define a strict {user,assistant} role allowlist'
        );
        $this->assertStringNotContainsString(
            "['system', 'user', 'assistant']",
            $this->contents,
            'Caller-supplied system messages must not remain in the allowlist'
        );
    }

    public function testInvalidRoleThrows(): void
    {
        // Source-level evidence that DataChatResource rejects invalid role
        // values. Either an explicit BadRequestException throw inside a
        // role-check branch, or in_array(..., true) used negatively.
        $hasNegativeInArrayCheck =
            preg_match('/!\s*in_array\s*\([^)]+,[^)]+,\s*true\s*\)/', $this->contents) === 1;
        $hasInvalidRoleException =
            (bool) preg_match('/role\s+must\s+be|invalid\s+role|messages\[/i', $this->contents);

        $this->assertTrue(
            $hasNegativeInArrayCheck && $hasInvalidRoleException,
            'DataChatResource must throw on roles outside the allowlist'
        );
    }

    public function testRoleIsNotPassedThroughUnchecked(): void
    {
        // The vulnerable pattern was:  'role' => $msg['role'] ?? 'user',
        // i.e. a fallback default with no allowlist on the provided value.
        // After the fix, the role still appears in the provider message,
        // but it must have been validated upstream — we assert that the
        // allowlist appears BEFORE the runAgenticLoop call so validation
        // happens before fan-out.
        $allowlistPos = false;
        foreach (['$validRoles', "'system'", '"system"'] as $needle) {
            $pos = strpos($this->contents, $needle);
            if ($pos !== false && ($allowlistPos === false || $pos < $allowlistPos)) {
                $allowlistPos = $pos;
            }
        }
        $loopPos = strpos($this->contents, 'runAgenticLoop(');

        $this->assertNotFalse($allowlistPos, 'role allowlist marker must appear in source');
        $this->assertNotFalse($loopPos, 'runAgenticLoop call must appear in source');
        $this->assertLessThan(
            $loopPos,
            $allowlistPos,
            'Role validation must happen BEFORE runAgenticLoop fan-out'
        );
    }
}
