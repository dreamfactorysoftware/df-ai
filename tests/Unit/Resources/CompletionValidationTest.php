<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Resources;

use DreamFactory\Core\AI\Resources\CompletionResource;
use DreamFactory\Core\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests the input validation logic in CompletionResource.
 *
 * Uses reflection to call the private validatePayload() method directly
 * since bootstrapping the full DreamFactory stack is unnecessary for
 * pure validation logic.
 */
class CompletionValidationTest extends TestCase
{
    private ReflectionMethod $validate;
    private CompletionResource $resource;

    protected function setUp(): void
    {
        // Create a CompletionResource without calling the constructor
        // (which needs DreamFactory service context). We only need
        // access to the validation method.
        $ref = new \ReflectionClass(CompletionResource::class);
        $this->resource = $ref->newInstanceWithoutConstructor();

        $this->validate = $ref->getMethod('validatePayload');
        $this->validate->setAccessible(true);
    }

    public function testValidPayloadPasses(): void
    {
        $this->validate->invoke($this->resource, [
            'prompt' => 'Hello world',
        ]);
        // No exception means success
        $this->assertTrue(true);
    }

    public function testValidPayloadWithOptionsPass(): void
    {
        $this->validate->invoke($this->resource, [
            'prompt' => 'Hello world',
            'max_tokens' => 100,
            'temperature' => 0.5,
        ]);
        $this->assertTrue(true);
    }

    public function testMissingPromptThrows(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('prompt');

        $this->validate->invoke($this->resource, []);
    }

    public function testEmptyPromptThrows(): void
    {
        $this->expectException(BadRequestException::class);

        $this->validate->invoke($this->resource, ['prompt' => '']);
    }

    public function testWhitespaceOnlyPromptThrows(): void
    {
        $this->expectException(BadRequestException::class);

        $this->validate->invoke($this->resource, ['prompt' => '   ']);
    }

    public function testNonStringPromptThrows(): void
    {
        $this->expectException(BadRequestException::class);

        $this->validate->invoke($this->resource, ['prompt' => 12345]);
    }

    public function testOverlongPromptThrows(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('100,000');

        $this->validate->invoke($this->resource, [
            'prompt' => str_repeat('a', 100_001),
        ]);
    }

    public function testMaxLengthPromptPasses(): void
    {
        $this->validate->invoke($this->resource, [
            'prompt' => str_repeat('a', 100_000),
        ]);
        $this->assertTrue(true);
    }

    public function testNegativeMaxTokensThrows(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('max_tokens');

        $this->validate->invoke($this->resource, [
            'prompt' => 'test',
            'max_tokens' => -1,
        ]);
    }

    public function testZeroMaxTokensThrows(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('max_tokens');

        $this->validate->invoke($this->resource, [
            'prompt' => 'test',
            'max_tokens' => 0,
        ]);
    }

    public function testNonNumericMaxTokensThrows(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('max_tokens');

        $this->validate->invoke($this->resource, [
            'prompt' => 'test',
            'max_tokens' => 'lots',
        ]);
    }

    public function testTemperatureBelowZeroThrows(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('temperature');

        $this->validate->invoke($this->resource, [
            'prompt' => 'test',
            'temperature' => -0.1,
        ]);
    }

    public function testTemperatureAboveTwoThrows(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('temperature');

        $this->validate->invoke($this->resource, [
            'prompt' => 'test',
            'temperature' => 2.1,
        ]);
    }

    public function testNonNumericTemperatureThrows(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('temperature');

        $this->validate->invoke($this->resource, [
            'prompt' => 'test',
            'temperature' => 'warm',
        ]);
    }

    public function testBoundaryTemperatureZeroPasses(): void
    {
        $this->validate->invoke($this->resource, [
            'prompt' => 'test',
            'temperature' => 0.0,
        ]);
        $this->assertTrue(true);
    }

    public function testBoundaryTemperatureTwoPasses(): void
    {
        $this->validate->invoke($this->resource, [
            'prompt' => 'test',
            'temperature' => 2.0,
        ]);
        $this->assertTrue(true);
    }
}
