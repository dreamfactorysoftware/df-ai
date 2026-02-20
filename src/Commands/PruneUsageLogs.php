<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Commands;

use DreamFactory\Core\AI\Models\AiUsageLog;
use Illuminate\Console\Command;

class PruneUsageLogs extends Command
{
    protected $signature = 'ai:prune-usage-logs
                            {--days= : Days of logs to retain (default from config)}';

    protected $description = 'Delete AI usage log entries older than the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('df-ai.usage_logging.retention_days', 90));

        $cutoff = now()->subDays($days);

        $deleted = AiUsageLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} AI usage log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
