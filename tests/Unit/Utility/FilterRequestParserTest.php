<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Utility;

use DreamFactory\Core\AI\Utility\FilterRequestParser;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FilterRequestParser. Covers the two on-the-wire shapes the
 * Gateway dashboard uses (CSV and repeated query params), plus the empty/
 * unknown-key handling that keeps the aggregator's filter contract clean.
 */
class FilterRequestParserTest extends TestCase
{
    private const ALLOWED = ['provider', 'service_id', 'model', 'status'];

    public function testReturnsEmptyWhenNoFilters(): void
    {
        $request = Request::create('/', 'GET');
        $this->assertSame([], FilterRequestParser::parse($request, self::ALLOWED));
    }

    public function testParsesSingleScalarValue(): void
    {
        $request = Request::create('/', 'GET', ['provider' => 'openai']);
        $this->assertSame(
            ['provider' => ['openai']],
            FilterRequestParser::parse($request, self::ALLOWED)
        );
    }

    public function testParsesCsvIntoArray(): void
    {
        $request = Request::create('/', 'GET', ['provider' => 'anthropic,openai,xai']);
        $this->assertSame(
            ['provider' => ['anthropic', 'openai', 'xai']],
            FilterRequestParser::parse($request, self::ALLOWED)
        );
    }

    public function testTrimsWhitespaceAroundCsvEntries(): void
    {
        $request = Request::create('/', 'GET', ['provider' => 'anthropic , openai ,  xai']);
        $this->assertSame(
            ['provider' => ['anthropic', 'openai', 'xai']],
            FilterRequestParser::parse($request, self::ALLOWED)
        );
    }

    public function testDropsEmptyCsvEntries(): void
    {
        // ?provider=anthropic,,openai shouldn't yield an empty filter value.
        $request = Request::create('/', 'GET', ['provider' => 'anthropic,,openai,']);
        $this->assertSame(
            ['provider' => ['anthropic', 'openai']],
            FilterRequestParser::parse($request, self::ALLOWED)
        );
    }

    public function testAcceptsRepeatedQueryParamArrayShape(): void
    {
        // ?service_id[]=1&service_id[]=2 — Symfony parses this as an array.
        $request = Request::create('/', 'GET', ['service_id' => ['1', '2']]);
        $this->assertSame(
            ['service_id' => ['1', '2']],
            FilterRequestParser::parse($request, self::ALLOWED)
        );
    }

    public function testIgnoresKeysNotInAllowList(): void
    {
        $request = Request::create('/', 'GET', [
            'provider' => 'openai',
            'sneaky'   => 'value',
            'role_id'  => '5', // not in our local ALLOWED list
        ]);
        $this->assertSame(
            ['provider' => ['openai']],
            FilterRequestParser::parse($request, self::ALLOWED)
        );
    }

    public function testDropsFilterWhenAllValuesEmpty(): void
    {
        // ?provider= (empty) should not appear in the output filter map at all.
        $request = Request::create('/', 'GET', ['provider' => '']);
        $this->assertSame([], FilterRequestParser::parse($request, self::ALLOWED));

        $request = Request::create('/', 'GET', ['provider' => ',,,']);
        $this->assertSame([], FilterRequestParser::parse($request, self::ALLOWED));
    }

    public function testHandlesMixOfPresentAndAbsentKeys(): void
    {
        $request = Request::create('/', 'GET', [
            'provider'   => 'openai,anthropic',
            'status'     => 'error',
        ]);
        $this->assertSame(
            [
                'provider' => ['openai', 'anthropic'],
                'status'   => ['error'],
            ],
            FilterRequestParser::parse($request, self::ALLOWED)
        );
    }
}
