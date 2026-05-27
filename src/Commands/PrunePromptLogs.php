<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Commands;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Models\AiPromptLog;
use Illuminate\Console\Command;

/**
 * Prune ai_prompt_log rows past the per-AI-Connection retention window.
 *
 * Why per-connection: regulated customers configure different retentions
 * for different services. A clinical-trial AI Connection may need 7
 * years (HIPAA documentation requirements), while a developer-sandbox
 * AI Connection may only retain 7 days. Storing the policy on the AI
 * Connection lets compliance officers control retention without code
 * changes; this command applies whatever's configured.
 *
 * Connections without a `prompt_log_retention_days` value fall back to
 * the global default (`df-ai.prompt_logging.retention_days`, 30 days
 * out of the box). 0 = retain forever.
 *
 * Schedule via app/Console/Kernel.php to run nightly. Idempotent — re-
 * running the same day is a no-op.
 */
class PrunePromptLogs extends Command
{
    protected $signature = 'ai:prune-prompt-logs
                            {--days= : Override retention (days). 0 = retain forever.}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete ai_prompt_log entries past their per-AI-Connection retention window';

    public function handle(): int
    {
        $override = $this->option('days') !== null ? (int) $this->option('days') : null;
        $dryRun = (bool) $this->option('dry-run');

        $globalDefault = (int) config('df-ai.prompt_logging.retention_days', 30);

        // Build a per-service cutoff map so we can apply different
        // retentions in one pass — much faster than per-row checks for
        // large prompt logs.
        $perServiceCutoffs = $this->buildCutoffMap($override, $globalDefault);

        $totalDeleted = 0;
        foreach ($perServiceCutoffs as $serviceId => $cutoff) {
            if ($cutoff === null) {
                $this->line("svc={$serviceId}: retention=forever, skipping");
                continue;
            }

            $query = AiPromptLog::query()
                ->where('service_id', $serviceId)
                ->where('created_at', '<', $cutoff);

            if ($dryRun) {
                $count = $query->count();
                $this->line("svc={$serviceId}: would prune {$count} rows older than {$cutoff->toDateString()}");
                continue;
            }

            $deleted = $query->delete();
            $totalDeleted += $deleted;
            $this->line("svc={$serviceId}: pruned {$deleted} rows older than {$cutoff->toDateString()}");
        }

        if ($dryRun) {
            $this->info('Dry-run complete; no rows deleted.');
        } else {
            $this->info("Pruned {$totalDeleted} AI prompt log entries total.");
        }

        return self::SUCCESS;
    }

    /**
     * Build a map of service_id → cutoff Carbon (or null = forever).
     *
     * @return array<int, ?\Carbon\CarbonInterface>
     */
    private function buildCutoffMap(?int $override, int $globalDefault): array
    {
        $now = now();
        $map = [];

        if ($override !== null) {
            // Manual override applies to all services uniformly.
            $cutoff = $override === 0 ? null : $now->copy()->subDays($override);
            foreach (AiPromptLog::query()->distinct()->pluck('service_id') as $svcId) {
                $map[(int) $svcId] = $cutoff;
            }
            return $map;
        }

        $configs = AiConnectionConfig::query()
            ->select('service_id', 'prompt_log_retention_days')
            ->get();

        foreach ($configs as $cfg) {
            $days = $cfg->prompt_log_retention_days;
            if ($days === null) {
                $days = $globalDefault;
            }
            $days = (int) $days;
            $map[(int) $cfg->service_id] = $days === 0 ? null : $now->copy()->subDays($days);
        }

        // Catch services with prompt log rows but no config row (rare,
        // but defensible — a deleted AI Connection's old prompt rows
        // would otherwise stay forever). Use the global default.
        $orphans = AiPromptLog::query()->distinct()->pluck('service_id');
        foreach ($orphans as $svcId) {
            $svcId = (int) $svcId;
            if (!array_key_exists($svcId, $map)) {
                $map[$svcId] = $globalDefault === 0 ? null : $now->copy()->subDays($globalDefault);
            }
        }

        return $map;
    }
}
