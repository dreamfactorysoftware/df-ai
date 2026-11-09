<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Http\Controllers\InternalBudgetController;
use DreamFactory\Core\AI\Models\AiBudget;
use DreamFactory\Core\AI\Services\BudgetAlerter;
use DreamFactory\Core\AI\Tests\Support\BudgetDatabase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Alert dispatch end to end, short of the network: real budget rows, the
 * real webhook rules, and a Guzzle mock in place of the receiver.
 */
class BudgetAlerterDispatchTest extends TestCase
{
    use BudgetDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];
    private ?Container $previousContainer = null;
    private Repository $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBudgetDatabase();
        Carbon::setTestNow(Carbon::create(2026, 11, 17, 14, 30, 0, 'UTC'));

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $this->config = new Repository(['df-ai' => ['budgets' => ['webhooks' => [
            'enabled'       => true,
            'allowed_hosts' => 'siem.corp.internal',
        ]]]]);
        $container->instance('config', $this->config);
        $container->instance('log', new NullLogger());
        $container->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 10, new Response(204))));
        $stack->push(Middleware::history($this->sent));
        $this->setClient(new Client(['handler' => $stack]));
    }

    protected function tearDown(): void
    {
        $this->setClient(null);
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function setClient(?Client $client): void
    {
        $property = new \ReflectionProperty(BudgetAlerter::class, 'client');
        $property->setValue(null, $client);
    }

    private function alertingBudget(string $url, ?string $header = 'Bearer receiver-secret'): AiBudget
    {
        $budget = new AiBudget([
            'dimension_type' => 'global',
            'period'         => 'monthly',
            'budget_usd'     => 10,
            'hard_stop'      => false,
            'label'          => 'Platform total',
            'is_active'      => true,
        ]);
        $budget->alert_webhook_url = $url;
        $budget->alert_webhook_auth_header = $header;
        $budget->save();

        return $budget->fresh();
    }

    public function testNothingIsSentWhileWebhooksAreOff(): void
    {
        $this->config->set('df-ai.budgets.webhooks.enabled', false);
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');
        $this->spend(9, '2026-11-17 14:00:00');

        BudgetAlerter::evaluateBudget($budget);

        $this->assertCount(0, $this->sent);
        // Left unmarked, so the alert fires once an operator turns webhooks on.
        $this->assertNull($budget->fresh()->last_alerted_80_at);

        $this->config->set('df-ai.budgets.webhooks.enabled', true);
        BudgetAlerter::evaluateBudget($budget->fresh());
        $this->assertCount(1, $this->sent);
    }

    public function testAnAllowedReceiverGetsEachThresholdOnceWithTheStoredHeader(): void
    {
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');

        $this->spend(5, '2026-11-17 14:00:00');
        BudgetAlerter::evaluateBudget($budget->fresh());
        BudgetAlerter::evaluateBudget($budget->fresh()); // same threshold again: no second alert

        $this->spend(3, '2026-11-17 14:05:00');
        BudgetAlerter::evaluateBudget($budget->fresh());

        $this->spend(2, '2026-11-17 14:10:00');
        BudgetAlerter::evaluateBudget($budget->fresh());
        BudgetAlerter::evaluateBudget($budget->fresh());

        $this->assertCount(3, $this->sent);
        $thresholds = [];
        foreach ($this->sent as $transaction) {
            $request = $transaction['request'];
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://siem.corp.internal/hook', (string) $request->getUri());
            $this->assertSame('Bearer receiver-secret', $request->getHeaderLine('Authorization'));
            $thresholds[] = json_decode((string) $request->getBody(), true)['threshold_pct'];
        }
        $this->assertSame([50, 80, 100], $thresholds);
    }

    public function testTwoRequestsThatCrossAThresholdTogetherSendOneAlert(): void
    {
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');
        $this->spend(9, '2026-11-17 14:00:00');

        // Both requests loaded the budget before either marked the threshold.
        $first = $budget->fresh();
        $second = $budget->fresh();

        BudgetAlerter::evaluateBudget($first);
        BudgetAlerter::evaluateBudget($second);

        $this->assertCount(1, $this->sent);
        $this->assertNotNull($budget->fresh()->last_alerted_80_at);
    }

    public function testAThresholdAlertsAgainInTheNextPeriod(): void
    {
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');
        $this->spend(9, '2026-11-17 14:00:00');
        BudgetAlerter::evaluateBudget($budget->fresh());

        Carbon::setTestNow(Carbon::create(2026, 12, 3, 9, 0, 0, 'UTC'));
        $this->spend(9, '2026-12-03 08:00:00');
        BudgetAlerter::evaluateBudget($budget->fresh());

        $this->assertCount(2, $this->sent);
    }

    public function testAStoredUrlThatIsNotAllowedIsRefusedAtSendTime(): void
    {
        // A row saved before the rules existed, or before the allow list
        // changed. The save-time check never saw it.
        foreach ([
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:8080/internal',
            'https://other.example.com/hook',
            'https://user:pw@siem.corp.internal/hook',
        ] as $url) {
            $budget = $this->alertingBudget($url);
            $this->spend(9, '2026-11-17 14:00:00');

            BudgetAlerter::evaluateBudget($budget);
        }

        $this->assertCount(0, $this->sent, 'no request may leave for a refused URL');
    }

    public function testWithoutAnAllowListAPrivateAddressIsRefusedAtSendTime(): void
    {
        $this->config->set('df-ai.budgets.webhooks.allowed_hosts', '');
        $budget = $this->alertingBudget('http://10.20.30.40/hook');
        $this->spend(9, '2026-11-17 14:00:00');

        BudgetAlerter::evaluateBudget($budget);

        $this->assertCount(0, $this->sent);
    }

    public function testTheSendHasAShortTimeout(): void
    {
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');
        $this->spend(9, '2026-11-17 14:00:00');

        BudgetAlerter::evaluateBudget($budget);

        $this->assertCount(1, $this->sent);
        $this->assertSame(5, $this->sent[0]['options']['timeout']);
    }

    public function testAHeaderThatCannotBeDecryptedDoesNotThrow(): void
    {
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');
        \Illuminate\Database\Capsule\Manager::table('ai_budget')
            ->where('id', $budget->id)
            ->update(['alert_webhook_auth_header' => 'not-ciphertext']);
        $this->spend(9, '2026-11-17 14:00:00');

        BudgetAlerter::evaluateBudget($budget->fresh());

        $this->assertCount(0, $this->sent, 'the alert is dropped, the AI call is not failed');
    }

    // ─── the stored auth header when a rule is edited ─────────────────────

    public function testABlankHeaderKeepsTheStoredOne(): void
    {
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');
        $budget->fill(['label' => 'Renamed', 'alert_webhook_url' => 'https://siem.corp.internal/v2/hook']);

        InternalBudgetController::resolveAuthHeader($budget, false, '');
        $budget->save();

        $this->assertSame('Bearer receiver-secret', $budget->fresh()->alert_webhook_auth_header);
    }

    public function testMovingTheUrlToAnotherHostDropsTheStoredHeader(): void
    {
        // The editor cannot read the header. Without this, pointing the URL
        // at their own server would deliver it on the next alert.
        foreach ([
            'https://collector.example.net/hook',
            'http://siem.corp.internal/hook',
            'https://siem.corp.internal:8443/hook',
        ] as $moved) {
            $budget = $this->alertingBudget('https://siem.corp.internal/hook');
            $budget->fill(['alert_webhook_url' => $moved]);

            InternalBudgetController::resolveAuthHeader($budget, false, '');
            $budget->save();

            $this->assertFalse($budget->fresh()->hasAlertWebhookAuth(), $moved);
        }
    }

    public function testANewHeaderSentWithANewHostIsStored(): void
    {
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');
        $budget->fill(['alert_webhook_url' => 'https://collector.example.net/hook']);

        InternalBudgetController::resolveAuthHeader($budget, false, 'Bearer new-secret');
        $budget->save();

        $this->assertSame('Bearer new-secret', $budget->fresh()->alert_webhook_auth_header);
    }

    public function testTheClearFlagRemovesTheHeader(): void
    {
        $budget = $this->alertingBudget('https://siem.corp.internal/hook');

        InternalBudgetController::resolveAuthHeader($budget, true, 'Bearer ignored');
        $budget->save();

        $this->assertFalse($budget->fresh()->hasAlertWebhookAuth());
    }

    public function testANewRuleStoresTheHeaderItIsGiven(): void
    {
        $budget = new AiBudget(['dimension_type' => 'global', 'period' => 'monthly', 'budget_usd' => 5, 'label' => 'New']);
        $budget->alert_webhook_url = 'https://siem.corp.internal/hook';

        InternalBudgetController::resolveAuthHeader($budget, false, 'Bearer first');
        $budget->save();

        $this->assertSame('Bearer first', $budget->fresh()->alert_webhook_auth_header);
    }
}
