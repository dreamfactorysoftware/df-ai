<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Utility;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Utility\UsageRates;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UsageRates' pure helpers — rate resolution chain and the
 * cost math. The DB-touching estimate() method is exercised end-to-end by
 * the Integration/UsageEndpointSmokeTest.sh script.
 */
class UsageRatesTest extends TestCase
{
    public function testCostForApplies1kDivisor(): void
    {
        // 1000 input tokens at $0.003/1k = $0.003
        // 500 output tokens at $0.015/1k = $0.0075
        // Total = $0.0105
        $this->assertEqualsWithDelta(
            0.0105,
            UsageRates::costFor(1000, 500, 0.003, 0.015),
            0.000001
        );
    }

    public function testCostForHandlesZeroAndFractional(): void
    {
        $this->assertSame(0.0, UsageRates::costFor(0, 0, 0.003, 0.015));

        // 13 input × 0.003/1000 + 4 output × 0.015/1000 = 0.000039 + 0.00006
        $this->assertEqualsWithDelta(
            0.000099,
            UsageRates::costFor(13, 4, 0.003, 0.015),
            1e-9
        );
    }

    public function testCostForClampsNegativeTokenCounts(): void
    {
        // Defensive: a buggy provider might emit a -1 sentinel. Don't refund.
        $this->assertSame(0.0, UsageRates::costFor(-100, -50, 0.003, 0.015));
        $this->assertEqualsWithDelta(
            0.00006,
            UsageRates::costFor(-100, 4, 0.003, 0.015),
            1e-9
        );
    }

    public function testParseModelRatesEmptyInputs(): void
    {
        $this->assertSame([], UsageRates::parseModelRates(null));
        $this->assertSame([], UsageRates::parseModelRates(''));
        $this->assertSame([], UsageRates::parseModelRates('not-json'));
        $this->assertSame([], UsageRates::parseModelRates('"a string"'));
        $this->assertSame([], UsageRates::parseModelRates('123'));
    }

    public function testParseModelRatesIndexesByModel(): void
    {
        $json = json_encode([
            ['model' => 'gpt-4o', 'input_per_1k' => 0.0025, 'output_per_1k' => 0.01],
            ['model' => 'gpt-4o-mini', 'input_per_1k' => 0.00015, 'output_per_1k' => 0.0006],
        ]);

        $rates = UsageRates::parseModelRates($json);

        $this->assertSame([0.0025, 0.01], $rates['gpt-4o']);
        $this->assertSame([0.00015, 0.0006], $rates['gpt-4o-mini']);
    }

    public function testParseModelRatesSkipsRowsWithoutModel(): void
    {
        // Saving-time validation rejects these, but parseModelRates must
        // also be defensive against historical bad rows.
        $json = json_encode([
            ['input_per_1k' => 0.001],            // no model
            ['model' => '', 'input_per_1k' => 0.001], // empty model
            ['model' => 'gpt-4o', 'input_per_1k' => 0.0025],
        ]);

        $rates = UsageRates::parseModelRates($json);

        $this->assertCount(1, $rates);
        $this->assertSame([0.0025, 0.0], $rates['gpt-4o']);
    }

    public function testResolveRatesFromConfigFallsBackToDefaultsWhenNoConfig(): void
    {
        // Per the DEFAULT_RATES table: anthropic=0.003/0.015
        $this->assertSame(
            [0.003, 0.015],
            UsageRates::resolveRatesFromConfig(null, 'anthropic', 'claude-haiku')
        );
    }

    public function testResolveRatesFromConfigReturnsZeroForUnknownProvider(): void
    {
        $this->assertSame(
            [0.0, 0.0],
            UsageRates::resolveRatesFromConfig(null, 'made-up-provider', 'whatever')
        );
    }

    public function testResolveRatesFromConfigPrefersPerModelOverFlat(): void
    {
        $config = $this->makeConfig([
            'cost_per_1k_input' => 0.001,
            'cost_per_1k_output' => 0.005,
            'model_rates' => json_encode([
                ['model' => 'claude-haiku-4-5', 'input_per_1k' => 0.0008, 'output_per_1k' => 0.004],
            ]),
        ]);

        $this->assertSame(
            [0.0008, 0.004],
            UsageRates::resolveRatesFromConfig($config, 'anthropic', 'claude-haiku-4-5')
        );
    }

    public function testResolveRatesFromConfigFallsThroughToFlatForUnknownModel(): void
    {
        $config = $this->makeConfig([
            'cost_per_1k_input' => 0.001,
            'cost_per_1k_output' => 0.005,
            'model_rates' => json_encode([
                ['model' => 'claude-haiku-4-5', 'input_per_1k' => 0.0008, 'output_per_1k' => 0.004],
            ]),
        ]);

        // claude-opus-4-5 isn't in the rate sheet → use the flat rate.
        $this->assertSame(
            [0.001, 0.005],
            UsageRates::resolveRatesFromConfig($config, 'anthropic', 'claude-opus-4-5')
        );
    }

    public function testResolveRatesFromConfigFallsThroughToDefaultWhenFlatNotSet(): void
    {
        $config = $this->makeConfig([
            'cost_per_1k_input' => null,
            'cost_per_1k_output' => null,
            'model_rates' => null,
        ]);

        // Hits DEFAULT_RATES['anthropic'].
        $this->assertSame(
            [0.003, 0.015],
            UsageRates::resolveRatesFromConfig($config, 'anthropic', 'whatever')
        );
    }

    public function testResolveRatesFromConfigUsesFlatWhenOnlyOneSideSet(): void
    {
        // If only input is set, output defaults to 0 — treat the admin's
        // partial input as intentional.
        $config = $this->makeConfig([
            'cost_per_1k_input' => 0.002,
            'cost_per_1k_output' => null,
            'model_rates' => null,
        ]);

        $this->assertSame(
            [0.002, 0.0],
            UsageRates::resolveRatesFromConfig($config, 'openai', 'gpt-4o')
        );
    }

    /**
     * Build an AiConnectionConfig instance without persisting it. Eloquent's
     * setRawAttributes lets us bypass mutators/connection requirements so the
     * test runs without a database.
     *
     * @param array<string, mixed> $attrs
     */
    private function makeConfig(array $attrs): AiConnectionConfig
    {
        $config = new AiConnectionConfig();
        $config->setRawAttributes($attrs, true);
        return $config;
    }
}
