<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Services;

use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Utility\Environment;
use DreamFactory\Core\Utility\Session;

/**
 * The one gate in front of the budget and model alias management API.
 *
 * Both controllers call it on every verb, so the two can never drift apart
 * and the admin UI can probe either route to learn whether the caller may
 * manage budgets. It decides who may manage rules. It never decides whether
 * a rule is enforced: BudgetEnforcer applies existing rules on every tier
 * and for every caller.
 */
class BudgetAccess
{
    public const MODE_SYSADMIN = 'sysadmin';
    public const MODE_ADMIN = 'admin';

    private const LICENSE_RANK = [
        LicenseLevel::OPEN_SOURCE => 0,
        LicenseLevel::SILVER      => 1,
        LicenseLevel::GOLD        => 2,
    ];

    /**
     * Whether the management routes exist at all on this server. Only a
     * clear "on" turns them on: an unset or misspelled value leaves them off.
     */
    public static function managementEnabled(mixed $setting = null): bool
    {
        $setting ??= config('df-ai.budgets.management_enabled', false);

        return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    /** Reason the calling session may not manage budgets, or null when it may. */
    public static function denial(): ?string
    {
        $requiredLicense = config('df-ai.budgets.required_license', LicenseLevel::GOLD);

        return self::decide(
            // False for an administrator that has a role, which is how a
            // restricted administrator is stored.
            (bool) Session::isSysAdmin(),
            (bool) session('user.is_sys_admin'),
            (string) config('df-ai.budgets.manage_access', self::MODE_SYSADMIN),
            $requiredLicense === null || $requiredLicense === '' ? null : (string) $requiredLicense,
            // Only looked up when a license is required.
            fn(): string => (string) Environment::getLicenseLevel(),
        );
    }

    /**
     * Pure decision, exposed for unit tests.
     *
     * @param bool          $isFullAdmin     administrator with no role
     * @param bool          $isAnyAdmin      administrator, restricted or not
     * @param string        $mode            MODE_SYSADMIN or MODE_ADMIN; anything else is read as MODE_SYSADMIN
     * @param ?string       $requiredLicense lowest license allowed, null for no check
     * @param callable():string $license     the installed license level
     */
    public static function decide(
        bool $isFullAdmin,
        bool $isAnyAdmin,
        string $mode,
        ?string $requiredLicense,
        callable $license,
    ): ?string {
        $allowed = $mode === self::MODE_ADMIN ? $isAnyAdmin : $isFullAdmin;
        if (!$allowed) {
            return 'Admin access required.';
        }

        if ($requiredLicense !== null) {
            $required = strtoupper(trim($requiredLicense));
            // An unknown value must not open the gate: read it as the top tier.
            $needed = self::LICENSE_RANK[$required] ?? self::LICENSE_RANK[LicenseLevel::GOLD];
            $have = self::LICENSE_RANK[strtoupper(trim($license()))] ?? 0;
            if ($have < $needed) {
                $name = isset(self::LICENSE_RANK[$required]) ? $required : LicenseLevel::GOLD;

                return "AI budget management requires a {$name} license.";
            }
        }

        return null;
    }
}
