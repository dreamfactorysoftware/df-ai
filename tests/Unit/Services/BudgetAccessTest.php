<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Services\BudgetAccess;
use PHPUnit\Framework\TestCase;

/**
 * Who may manage budgets and model aliases. The defaults are the restrictive
 * answers to the open questions in the budget spec: full administrators
 * only, on a GOLD license.
 */
class BudgetAccessTest extends TestCase
{
    private static function license(string $level): callable
    {
        return fn(): string => $level;
    }

    public function testManagementIsOffUnlessClearlyTurnedOn(): void
    {
        foreach ([false, 'false', '0', 0, '', 'off', 'no', 'maybe', 'enabled'] as $setting) {
            $this->assertFalse(BudgetAccess::managementEnabled($setting), var_export($setting, true));
        }
        foreach ([true, 'true', '1', 1, 'on', 'yes'] as $setting) {
            $this->assertTrue(BudgetAccess::managementEnabled($setting), var_export($setting, true));
        }
    }

    public function testManagementRoutesAreRegisteredOnlyBehindTheSwitch(): void
    {
        // The switch is worthless if a route is registered outside it. Same
        // source check the Data Chat rate-limit tests use: no app container.
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ServiceProvider.php');
        $open = strpos($source, 'if (BudgetAccess::managementEnabled()) {');
        $this->assertNotFalse($open, 'the management switch is missing from ServiceProvider');
        $close = strpos($source, "\n            }\n", $open);
        $this->assertNotFalse($close);

        $outside = substr($source, 0, $open) . substr($source, $close);
        $this->assertStringNotContainsString('_internal/ai/budgets', $outside);
        $this->assertStringNotContainsString('_internal/ai/aliases', $outside);

        $inside = substr($source, $open, $close - $open);
        $this->assertSame(4, substr_count($inside, '_internal/ai/budgets'));
        $this->assertSame(4, substr_count($inside, '_internal/ai/aliases'));
    }

    public function testFullAdminOnGoldIsAllowed(): void
    {
        $this->assertNull(BudgetAccess::decide(true, true, 'sysadmin', 'GOLD', self::license('GOLD')));
    }

    public function testRestrictedAdminIsRefusedByDefault(): void
    {
        // A restricted administrator is an administrator with a role, so it
        // is not a full administrator.
        $this->assertSame(
            'Admin access required.',
            BudgetAccess::decide(false, true, 'sysadmin', 'GOLD', self::license('GOLD')),
        );
    }

    public function testRestrictedAdminIsAllowedInAdminMode(): void
    {
        $this->assertNull(BudgetAccess::decide(false, true, 'admin', 'GOLD', self::license('GOLD')));
    }

    public function testNonAdminIsRefusedInEveryMode(): void
    {
        foreach (['sysadmin', 'admin', 'everyone', ''] as $mode) {
            $this->assertSame(
                'Admin access required.',
                BudgetAccess::decide(false, false, $mode, null, self::license('GOLD')),
                "mode '{$mode}'",
            );
        }
    }

    public function testUnknownModeIsReadAsTheRestrictiveOne(): void
    {
        $this->assertNotNull(BudgetAccess::decide(false, true, 'ADMIN ', 'GOLD', self::license('GOLD')));
        $this->assertNotNull(BudgetAccess::decide(false, true, 'anyone', 'GOLD', self::license('GOLD')));
    }

    public function testLicenseBelowTheRequiredTierIsRefused(): void
    {
        $this->assertSame(
            'AI budget management requires a GOLD license.',
            BudgetAccess::decide(true, true, 'sysadmin', 'GOLD', self::license('SILVER')),
        );
        $this->assertSame(
            'AI budget management requires a SILVER license.',
            BudgetAccess::decide(true, true, 'sysadmin', 'SILVER', self::license('OPEN SOURCE')),
        );
    }

    public function testLicenseAtOrAboveTheRequiredTierIsAllowed(): void
    {
        $this->assertNull(BudgetAccess::decide(true, true, 'sysadmin', 'SILVER', self::license('SILVER')));
        $this->assertNull(BudgetAccess::decide(true, true, 'sysadmin', 'silver', self::license('GOLD')));
    }

    public function testNoRequiredLicenseSkipsTheLookup(): void
    {
        $license = function (): string {
            $this->fail('the license must not be looked up when none is required');
        };

        $this->assertNull(BudgetAccess::decide(true, true, 'sysadmin', null, $license));
    }

    public function testUnknownRequiredLicenseIsReadAsTheTopTier(): void
    {
        // A typo in the setting must not open the gate.
        $this->assertSame(
            'AI budget management requires a GOLD license.',
            BudgetAccess::decide(true, true, 'sysadmin', 'PLATINUM', self::license('SILVER')),
        );
        $this->assertNull(BudgetAccess::decide(true, true, 'sysadmin', 'PLATINUM', self::license('GOLD')));
    }

    public function testAccessIsCheckedBeforeTheLicense(): void
    {
        // A caller that is not an administrator learns nothing about the license.
        $this->assertSame(
            'Admin access required.',
            BudgetAccess::decide(false, false, 'sysadmin', 'GOLD', self::license('SILVER')),
        );
    }
}
