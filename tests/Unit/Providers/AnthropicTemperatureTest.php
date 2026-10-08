<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Providers;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use DreamFactory\Core\AI\Providers\AnthropicProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AnthropicProvider with the HTTP layer replaced: records every payload and
 * answers from a scripted list of replies (arrays) or failures (exceptions).
 */
final class RecordingAnthropicProvider extends AnthropicProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $payloads = [];

    /** @var array<int, array<string, mixed>|\Throwable> */
    public array $replies = [];

    public function accepts(string $model): bool
    {
        return $this->supportsTemperature($model);
    }

    protected function request(string $method, string $uri, array $options = []): array
    {
        $this->payloads[] = $options['json'] ?? [];
        $reply = array_shift($this->replies) ?? self::textReply('ok');
        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply;
    }

    /** A current-model reply: a thinking block, then the text. */
    public static function textReply(string $text): array
    {
        return [
            'id' => 'msg_test',
            'model' => 'test-model',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => $text],
            ],
        ];
    }
}

class AnthropicTemperatureTest extends TestCase
{
    private const USER = [['role' => 'user', 'content' => 'hi']];

    /** The provider reads config('df-ai.anthropic_version'); give the helper a container. */
    protected function setUp(): void
    {
        $container = new Container();
        $container->instance('config', new Repository(['df-ai' => ['anthropic_version' => '2023-06-01']]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    private function provider(string $model = ''): RecordingAnthropicProvider
    {
        return new RecordingAnthropicProvider('https://api.anthropic.com', 'test-key', $model, 1024, 0.4);
    }

    public function testOnlyGenerationsKnownToAcceptTemperatureGetIt(): void
    {
        $p = $this->provider();
        $accepting = [
            'claude-3-5-sonnet-20241022', 'claude-3-haiku-20240307', 'claude-3-7-sonnet-latest',
            'claude-sonnet-4-20250514', 'claude-opus-4-1', 'claude-sonnet-4-5', 'claude-haiku-4-5-20251001',
            'claude-opus-4-6', 'claude-sonnet-4-6-20260301',
        ];
        foreach ($accepting as $m) {
            $this->assertTrue($p->accepts($m), "$m accepts temperature");
        }
        $rejecting = [
            'claude-opus-4-7', 'claude-opus-4-7-20260401', 'claude-opus-4-8', 'claude-sonnet-5', 'claude-sonnet-5-5',
            'claude-opus-5', 'claude-opus-5-5', 'claude-fable-5-1', 'claude-mythos-5-1',
            // never seen: a newer family, a renamed one, a future generation, nonsense
            'claude-haiku-5', 'claude-opus-6', 'claude-newfamily-7-2', 'not-a-claude-model', '',
        ];
        foreach ($rejecting as $m) {
            $this->assertFalse($p->accepts($m), "$m gets no temperature");
        }
    }

    public function testTemperatureIsSentOnlyToModelsThatAcceptIt(): void
    {
        $p = $this->provider('claude-sonnet-4-6');
        $p->chat(self::USER);
        $this->assertSame(0.4, $p->payloads[0]['temperature']);

        $p = $this->provider('claude-opus-5-5');
        $p->chat(self::USER, ['temperature' => 0.9]);
        $this->assertArrayNotHasKey('temperature', $p->payloads[0], 'even an explicit option is dropped');
        $this->assertSame('claude-opus-5-5', $p->payloads[0]['model']);
    }

    public function testAModelThatRejectsTemperatureIsRetriedWithoutItAndRemembered(): void
    {
        // Pretend a model this code believes accepts temperature stopped doing so.
        $model = 'claude-sonnet-4-5-20250929';
        $p = $this->provider($model);
        $p->replies = [
            AiProviderException::badRequest('anthropic', 400, '`temperature` is deprecated for this model'),
            RecordingAnthropicProvider::textReply('after retry'),
        ];
        $r = $p->chat(self::USER);
        $this->assertSame('after retry', $r['content']);
        $this->assertCount(2, $p->payloads);
        $this->assertArrayHasKey('temperature', $p->payloads[0]);
        $this->assertArrayNotHasKey('temperature', $p->payloads[1]);

        // Learned for the process: a fresh provider skips the parameter outright.
        $q = $this->provider($model);
        $q->chat(self::USER);
        $this->assertCount(1, $q->payloads);
        $this->assertArrayNotHasKey('temperature', $q->payloads[0]);
    }

    public function testOtherBadRequestsAreNotRetried(): void
    {
        $p = $this->provider('claude-sonnet-4-5');
        $p->replies = [AiProviderException::badRequest('anthropic', 400, 'max_tokens: must be at least 1')];
        try {
            $p->chat(self::USER);
            $this->fail('expected the bad request to surface');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('max_tokens', $e->getMessage());
        }
        $this->assertCount(1, $p->payloads, 'no retry for an unrelated 400');
    }

    public function testTextIsReadAfterAThinkingBlock(): void
    {
        $p = $this->provider('claude-opus-5-5');
        $p->replies = [RecordingAnthropicProvider::textReply('{"ok":true}')];
        $this->assertSame('{"ok":true}', $p->chat(self::USER)['content']);

        $p->replies = [['content' => [['type' => 'thinking', 'thinking' => '']], 'stop_reason' => 'refusal']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stop_reason: refusal');
        $p->chat(self::USER);
    }
}
