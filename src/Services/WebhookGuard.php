<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Services;

/**
 * Rules for the budget alert webhook, the one place this package sends a
 * request to a URL an administrator typed in.
 *
 * Off unless df-ai.budgets.webhooks.enabled is set. When on, a URL is checked
 * when the rule is saved and again when the alert is sent:
 *   - http or https only, no embedded credentials;
 *   - with df-ai.budgets.webhooks.allowed_hosts set, the host must be on it;
 *   - otherwise every address the host resolves to must be public, which
 *     keeps out loopback, private ranges and cloud metadata endpoints.
 * The send is pinned to the addresses that passed, so a DNS answer that
 * changes between the check and the request cannot redirect it.
 */
class WebhookGuard
{
    public static function enabled(): bool
    {
        return filter_var(config('df-ai.budgets.webhooks.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<int, string> lower-case host names from the allow list */
    public static function allowedHosts(): array
    {
        return self::parseHostList((string) config('df-ai.budgets.webhooks.allowed_hosts', ''));
    }

    /** Reason the URL may not be saved on a budget, or null when it may. */
    public static function rejection(string $url): ?string
    {
        if (!self::enabled()) {
            return 'Alert webhooks are turned off on this server.';
        }

        return self::inspect($url, self::allowedHosts(), [self::class, 'resolve'])['error'];
    }

    /**
     * Guzzle options that pin the request to the checked addresses.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when the URL may not be called
     */
    public static function requestOptions(string $url): array
    {
        if (!self::enabled()) {
            throw new \RuntimeException('Alert webhooks are turned off on this server.');
        }

        $result = self::inspect($url, self::allowedHosts(), [self::class, 'resolve']);
        if ($result['error'] !== null) {
            throw new \RuntimeException($result['error']);
        }

        return $result['pin'] === null ? [] : ['curl' => [CURLOPT_RESOLVE => [$result['pin']]]];
    }

    /**
     * Pure check, exposed for unit tests.
     *
     * @param array<int, string>                  $allowedHosts lower-case host names; empty means "public hosts only"
     * @param callable(string): array<int,string> $resolver     host name to its addresses
     * @return array{error: ?string, pin: ?string} pin is a CURLOPT_RESOLVE entry, null when there is nothing to pin
     */
    public static function inspect(string $url, array $allowedHosts, callable $resolver): array
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($parts === false || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return self::refuse('The alert webhook URL must be a full http or https URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::refuse('The alert webhook URL must not contain a user name or password.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($allowedHosts !== []) {
            if (!in_array($host, $allowedHosts, true)) {
                return self::refuse('The alert webhook host is not on the allowed list for this server.');
            }
            // The operator named this host, so its addresses are not judged.
            $addresses = $literal ? [] : $resolver($host);
        } else {
            $addresses = $literal ? [$host] : $resolver($host);
            if ($addresses === []) {
                return self::refuse('The alert webhook host does not resolve to an address.');
            }
            foreach ($addresses as $address) {
                if (!self::isPublicAddress($address)) {
                    return self::refuse(
                        'The alert webhook host resolves to a private or reserved address. '
                        . 'An operator must add it to the allowed hosts to use it.'
                    );
                }
            }
        }

        if ($literal || $addresses === []) {
            return ['error' => null, 'pin' => null];
        }

        $pinned = array_map(
            fn(string $a): string => str_contains($a, ':') ? "[{$a}]" : $a,
            $addresses,
        );

        return ['error' => null, 'pin' => "{$host}:{$port}:" . implode(',', $pinned)];
    }

    /** True only for an address on the public internet. */
    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // An IPv4 address carried inside IPv6 (::ffff:127.0.0.1, ::127.0.0.1,
        // 64:ff9b::7f00:1) reaches the IPv4 host, so judge that address.
        $packed = inet_pton($address);
        if ($packed !== false && strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);
            $mapped = "\0\0\0\0\0\0\0\0\0\0\xff\xff";
            $compatible = "\0\0\0\0\0\0\0\0\0\0\0\0";
            $nat64 = "\x00\x64\xff\x9b\0\0\0\0\0\0\0\0";
            if ($prefix === $mapped || $prefix === $nat64 || ($prefix === $compatible && $address !== '::')) {
                return self::isPublicAddress((string) inet_ntop(substr($packed, 12)));
            }
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) !== false;
    }

    /** @return array<int, string> */
    public static function parseHostList(string $list): array
    {
        $hosts = [];
        foreach (explode(',', $list) as $entry) {
            $entry = strtolower(trim(trim($entry), '[]'));
            if ($entry !== '') {
                $hosts[] = $entry;
            }
        }

        return array_values(array_unique($hosts));
    }

    /** @return array<int, string> every IPv4 and IPv6 address the host resolves to */
    public static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    /** @return array{error: string, pin: null} */
    private static function refuse(string $reason): array
    {
        return ['error' => $reason, 'pin' => null];
    }
}
