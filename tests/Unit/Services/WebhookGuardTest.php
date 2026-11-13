<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Services\WebhookGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The budget alert webhook posts from the server to a URL an administrator
 * typed in. These tests pin the rules that keep that request away from
 * internal addresses, with a fake resolver so no DNS lookup runs.
 */
class WebhookGuardTest extends TestCase
{
    /** @return callable(string): array<int, string> */
    private static function resolver(array $table): callable
    {
        return fn(string $host): array => $table[$host] ?? [];
    }

    #[DataProvider('internalAddresses')]
    public function testInternalAddressesAreNotPublic(string $address): void
    {
        $this->assertFalse(WebhookGuard::isPublicAddress($address), $address);
    }

    public static function internalAddresses(): array
    {
        return [
            'loopback'              => ['127.0.0.1'],
            'loopback high'         => ['127.255.255.254'],
            'any'                   => ['0.0.0.0'],
            'private 10'            => ['10.1.2.3'],
            'private 172'           => ['172.16.5.4'],
            'private 192'           => ['192.168.1.10'],
            'cloud metadata'        => ['169.254.169.254'],
            'carrier grade nat'     => ['100.64.0.1'],
            'ipv6 loopback'         => ['::1'],
            'ipv6 link local'       => ['fe80::1'],
            'ipv6 unique local'     => ['fd00::1'],
            'ipv4 mapped loopback'  => ['::ffff:127.0.0.1'],
            'ipv4 mapped metadata'  => ['::ffff:169.254.169.254'],
            'ipv4 compatible'       => ['::10.0.0.1'],
            'nat64 loopback'        => ['64:ff9b::7f00:1'],
            'not an address'        => ['example.com'],
        ];
    }

    public function testPublicAddressesArePublic(): void
    {
        $this->assertTrue(WebhookGuard::isPublicAddress('93.184.216.34'));
        $this->assertTrue(WebhookGuard::isPublicAddress('2606:2800:220:1:248:1893:25c8:1946'));
        $this->assertTrue(WebhookGuard::isPublicAddress('::ffff:93.184.216.34'));
    }

    #[DataProvider('refusedUrls')]
    public function testUrlIsRefused(string $url, array $dns): void
    {
        $result = WebhookGuard::inspect($url, [], self::resolver($dns));

        $this->assertNotNull($result['error'], $url);
        $this->assertNull($result['pin']);
    }

    public static function refusedUrls(): array
    {
        return [
            'ftp scheme'           => ['ftp://hooks.example.com/x', ['hooks.example.com' => ['93.184.216.34']]],
            'file scheme'          => ['file:///etc/passwd', []],
            'gopher scheme'        => ['gopher://hooks.example.com/x', ['hooks.example.com' => ['93.184.216.34']]],
            'no scheme'            => ['hooks.example.com/x', ['hooks.example.com' => ['93.184.216.34']]],
            'embedded credentials' => ['https://user:secret@hooks.example.com/x', ['hooks.example.com' => ['93.184.216.34']]],
            'embedded user only'   => ['https://token@hooks.example.com/x', ['hooks.example.com' => ['93.184.216.34']]],
            'loopback literal'     => ['http://127.0.0.1:8080/x', []],
            'metadata literal'     => ['http://169.254.169.254/latest/meta-data/', []],
            'ipv6 loopback'        => ['http://[::1]/x', []],
            'mapped loopback'      => ['http://[::ffff:127.0.0.1]/x', []],
            'localhost name'       => ['http://localhost/x', ['localhost' => ['127.0.0.1']]],
            'internal name'        => ['https://siem.corp.internal/hook', ['siem.corp.internal' => ['10.20.30.40']]],
            // One public and one private answer: every address must be public.
            'mixed answers'        => ['https://rebind.example.com/x', ['rebind.example.com' => ['93.184.216.34', '10.0.0.5']]],
            // The resolver turns a bare number into an address, as libc does.
            'decimal host'         => ['http://2130706433/x', ['2130706433' => ['127.0.0.1']]],
            'does not resolve'     => ['https://nowhere.invalid/x', []],
        ];
    }

    public function testPublicHostIsAcceptedAndPinnedToItsCheckedAddresses(): void
    {
        $result = WebhookGuard::inspect(
            'https://Hooks.Example.com/budget?x=1',
            [],
            self::resolver(['hooks.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']]),
        );

        $this->assertNull($result['error']);
        // The request is sent to these addresses, whatever DNS says later.
        $this->assertSame(
            'hooks.example.com:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]',
            $result['pin'],
        );
    }

    public function testHttpUrlPinsPort80AndAnExplicitPortIsKept(): void
    {
        $dns = self::resolver(['hooks.example.com' => ['93.184.216.34']]);

        $this->assertSame('hooks.example.com:80:93.184.216.34', WebhookGuard::inspect('http://hooks.example.com/x', [], $dns)['pin']);
        $this->assertSame('hooks.example.com:8443:93.184.216.34', WebhookGuard::inspect('https://hooks.example.com:8443/x', [], $dns)['pin']);
    }

    public function testAllowedHostMayBeInternal(): void
    {
        // Internal receivers are a real on-prem use. The operator names them.
        $result = WebhookGuard::inspect(
            'https://siem.corp.internal/hook',
            ['siem.corp.internal'],
            self::resolver(['siem.corp.internal' => ['10.20.30.40']]),
        );

        $this->assertNull($result['error']);
        $this->assertSame('siem.corp.internal:443:10.20.30.40', $result['pin']);
    }

    public function testWithAnAllowListEveryOtherHostIsRefused(): void
    {
        $result = WebhookGuard::inspect(
            'https://hooks.example.com/x',
            ['siem.corp.internal'],
            self::resolver(['hooks.example.com' => ['93.184.216.34']]),
        );

        $this->assertNotNull($result['error']);
    }

    public function testAllowListMatchesTheWholeHostOnly(): void
    {
        $dns = self::resolver(['siem.corp.internal.evil.example' => ['93.184.216.34']]);

        $this->assertNotNull(
            WebhookGuard::inspect('https://siem.corp.internal.evil.example/x', ['siem.corp.internal'], $dns)['error'],
        );
    }

    public function testParseHostList(): void
    {
        $this->assertSame([], WebhookGuard::parseHostList(''));
        $this->assertSame(
            ['siem.corp.internal', '10.0.0.9', 'fd00::9'],
            WebhookGuard::parseHostList(' SIEM.corp.internal , 10.0.0.9,, [fd00::9], siem.corp.internal '),
        );
    }
}
