<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Utility;

use DreamFactory\Core\AI\Utility\AuditStreamFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditStreamFormatter — the Elastic Common Schema
 * (ECS) shaper that powers SIEM forwarding (file sink, webhook, pull
 * endpoint).
 *
 * **What the contract pins:**
 *   1. ECS field paths — `@timestamp`, `event.kind`, `event.action`,
 *      `event.outcome`, `service.*`, `user.*`. Logstash + Elastic + Splunk
 *      pipelines query these by exact path.
 *   2. The `df.*` private namespace — DF-specific fields go under here
 *      per ECS's "Custom Fields" recommendation. SIEMs auto-index unknown
 *      fields, but pinning the namespace stops accidental top-level field
 *      pollution.
 *   3. NDJSON shape — one JSON object per line, no array wrapping. This
 *      is what Logstash's json_lines codec consumes.
 *   4. Splunk HEC + Datadog wrappers — vendor-specific envelopes that
 *      wrap (don't replace) the ECS event. Reverting them must yield
 *      the original ECS payload.
 *
 * Drift here breaks every customer's SIEM pipeline silently — they
 * just stop getting events.
 */
class AuditStreamFormatterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function sampleUsageRow(array $overrides = []): array
    {
        return array_merge([
            'id'              => 42,
            'request_id'      => '7d5f1d23-1111-2222-3333-444455556666',
            'service_id'      => 136,
            'user_id'         => 1,
            'role_id'         => 11,
            'app_id'          => 6,
            'provider'        => 'anthropic',
            'model'           => 'claude-haiku-4-5-20251001',
            'resource'        => 'chat',
            'input_tokens'    => 1854,
            'output_tokens'   => 13,
            'tool_call_count' => 2,
            'cost_usd'        => 0.001535,
            'latency_ms'      => 987,
            'status'          => 'success',
            'error_message'   => null,
            'created_at'      => '2026-04-29 21:02:50',
        ], $overrides);
    }

    // ─── ECS shape pinning ────────────────────────────────────────────

    public function testEmitsRequiredEcsFields(): void
    {
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow());

        // Top-level ECS required fields. Drift on these breaks every
        // customer's SIEM pipeline.
        $this->assertArrayHasKey('@timestamp', $event);
        $this->assertArrayHasKey('event', $event);
        $this->assertArrayHasKey('service', $event);
        $this->assertArrayHasKey('user', $event);
        $this->assertArrayHasKey('df', $event);
        $this->assertArrayHasKey('message', $event);

        // event.* sub-fields used by SIEM dashboards.
        $this->assertSame('event', $event['event']['kind']);
        $this->assertSame('ai_request', $event['event']['action']);
        $this->assertSame('success', $event['event']['outcome']);
        $this->assertSame('7d5f1d23-1111-2222-3333-444455556666', $event['event']['id']);
        $this->assertSame('dreamfactory.ai_gateway', $event['event']['dataset']);

        // service.* identifies the source.
        $this->assertSame('dreamfactory', $event['service']['name']);
        $this->assertSame('ai_gateway', $event['service']['type']);
        $this->assertSame(136, $event['service']['id']);
    }

    public function testTimestampIsIso8601Utc(): void
    {
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow());
        // ECS expects ISO-8601 with 'Z' UTC marker, not "+00:00" or naive.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $event['@timestamp']
        );
    }

    public function testDfNamespaceContainsAiMetrics(): void
    {
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow());

        // df.ai.* — the AI metrics live under the DF private namespace
        // per ECS custom-fields convention. SIEM dashboards key on these
        // exact paths.
        $this->assertSame('anthropic', $event['df']['ai']['provider']);
        $this->assertSame('claude-haiku-4-5-20251001', $event['df']['ai']['model']);
        $this->assertSame(1854, $event['df']['ai']['input_tokens']);
        $this->assertSame(13, $event['df']['ai']['output_tokens']);
        $this->assertSame(2, $event['df']['ai']['tool_call_count']);
        $this->assertEqualsWithDelta(0.001535, $event['df']['ai']['cost_usd'], 1e-9);
        $this->assertSame(987, $event['df']['ai']['latency_ms']);
        $this->assertSame('success', $event['df']['ai']['status']);

        // df.* attribution.
        $this->assertSame(11, $event['df']['role_id']);
        $this->assertSame(6, $event['df']['app_id']);
        $this->assertSame(136, $event['df']['service_id']);
        $this->assertSame('chat', $event['df']['resource']);
    }

    public function testErrorRowIncludesErrorBlockAndDeniedType(): void
    {
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow([
            'status'        => 'error',
            'error_message' => '429 rate limit exceeded',
            'cost_usd'      => 0,
        ]));

        $this->assertSame('failure', $event['event']['outcome']);
        $this->assertSame(['denied'], $event['event']['type']);
        $this->assertArrayHasKey('error', $event);
        $this->assertSame('429 rate limit exceeded', $event['error']['message']);
    }

    public function testPartialStatusMapsToUnknownEcsOutcome(): void
    {
        // ECS has only success/failure/unknown — partial maps to unknown.
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow(['status' => 'partial']));
        $this->assertSame('unknown', $event['event']['outcome']);
    }

    public function testPromptBlockAttachedWhenPromptRowProvided(): void
    {
        $event = AuditStreamFormatter::fromUsage(
            $this->sampleUsageRow(),
            [
                'request_id'          => '7d5f1d23-1111-2222-3333-444455556666',
                'request_payload'     => 'Hi [REDACTED:SSN]',
                'response_payload'    => 'OK [REDACTED:EMAIL]',
                'redaction_count'     => 2,
                'original_size_bytes' => 256,
            ]
        );
        $this->assertSame('Hi [REDACTED:SSN]', $event['df']['prompt']['redacted_text']);
        $this->assertSame(2, $event['df']['prompt']['redaction_count']);
        $this->assertSame(256, $event['df']['prompt']['original_size']);
        $this->assertSame('OK [REDACTED:EMAIL]', $event['df']['response']['redacted_text']);
    }

    public function testPromptBlockOmittedWhenNoPromptRow(): void
    {
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow(), null);
        $this->assertArrayNotHasKey('prompt', $event['df']);
        $this->assertArrayNotHasKey('response', $event['df']);
    }

    public function testMessageLineSummarizesEvent(): void
    {
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow());
        // The `message` field is what SIEMs show by default in event lists.
        $this->assertStringContainsString('AI chat anthropic/claude-haiku-4-5-20251001', $event['message']);
        $this->assertStringContainsString('in=1854', $event['message']);
        $this->assertStringContainsString('out=13', $event['message']);
        $this->assertStringContainsString('cost=0.001535', $event['message']);
    }

    public function testNullRoleAndAppGracefulInDfNamespace(): void
    {
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow([
            'role_id' => null,
            'app_id'  => null,
        ]));
        $this->assertNull($event['df']['role_id']);
        $this->assertNull($event['df']['app_id']);
    }

    // ─── NDJSON line ──────────────────────────────────────────────────

    public function testNdjsonLineProducesSingleLineJson(): void
    {
        $event = AuditStreamFormatter::fromUsage($this->sampleUsageRow());
        $line = AuditStreamFormatter::toNdjsonLine($event);
        // No newlines inside a single line (Logstash json_lines codec splits on \n).
        $this->assertStringNotContainsString("\n", $line);
        // Round-trips back.
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ai_request', $decoded['event']['action']);
    }

    public function testNdjsonPreservesUnicodeAndSlashes(): void
    {
        $event = AuditStreamFormatter::fromUsage(
            $this->sampleUsageRow(),
            [
                'request_id'      => 'x',
                'request_payload' => 'café 你好 https://example.com/x',
                'response_payload'=> '',
                'redaction_count' => 0,
            ]
        );
        $line = AuditStreamFormatter::toNdjsonLine($event);
        // Don't escape unicode (SIEM analysts want readable values).
        $this->assertStringContainsString('café', $line);
        $this->assertStringContainsString('你好', $line);
        // Don't double-escape forward slashes.
        $this->assertStringNotContainsString('https:\\/\\/example', $line);
    }

    // ─── Splunk HEC envelope ──────────────────────────────────────────

    public function testSplunkHecWrapsEventWithEpochAndSourcetype(): void
    {
        $ecs = AuditStreamFormatter::fromUsage($this->sampleUsageRow());
        $hec = AuditStreamFormatter::toSplunkHec($ecs);

        // HEC envelope shape Splunk's HTTP Event Collector consumes.
        $this->assertArrayHasKey('time', $hec);
        $this->assertIsInt($hec['time']);
        $this->assertSame('_json', $hec['sourcetype']);
        $this->assertSame('dreamfactory:ai_gateway', $hec['source']);
        $this->assertArrayHasKey('event', $hec);

        // The wrapped event is the unmodified ECS payload — verifiable
        // by round-tripping a known field.
        $this->assertSame('ai_request', $hec['event']['event']['action']);
    }

    public function testSplunkHecCustomSourcetype(): void
    {
        $ecs = AuditStreamFormatter::fromUsage($this->sampleUsageRow());
        $hec = AuditStreamFormatter::toSplunkHec($ecs, 'dreamfactory:ai');
        $this->assertSame('dreamfactory:ai', $hec['sourcetype']);
    }

    // ─── Datadog envelope ─────────────────────────────────────────────

    public function testDatadogAddsRoutingFields(): void
    {
        $ecs = AuditStreamFormatter::fromUsage($this->sampleUsageRow());
        $dd = AuditStreamFormatter::toDatadog($ecs);

        // Datadog routes/filters by ddsource + service.name + ddtags.
        $this->assertSame('dreamfactory', $dd['ddsource']);
        $this->assertSame('dreamfactory_ai_gateway', $dd['service']['name']);
        $this->assertStringContainsString('provider:anthropic', $dd['ddtags']);
        $this->assertStringContainsString('model:claude-haiku-4-5-20251001', $dd['ddtags']);
    }

    public function testDatadogPreservesEcsBody(): void
    {
        $ecs = AuditStreamFormatter::fromUsage($this->sampleUsageRow());
        $dd = AuditStreamFormatter::toDatadog($ecs);
        // The original ECS structure survives — Datadog log processors
        // can still index df.ai.* etc.
        $this->assertSame('ai_request', $dd['event']['action']);
        $this->assertSame(1854, $dd['df']['ai']['input_tokens']);
    }
}
