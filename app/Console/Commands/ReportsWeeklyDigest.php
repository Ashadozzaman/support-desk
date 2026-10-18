<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReportDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled Mondays 08:00 business timezone (routes/console.php). Mails are
 * queued notifications, so this command only builds the numbers.
 */
class ReportsWeeklyDigest extends Command
{
    protected $signature = 'reports:weekly-digest {--force : Send even if this week\'s digest already went out}';

    protected $description = 'Email the weekly report digest to everyone with reports.view.';

    public function handle(ReportDigestService $digest): int
    {
        $sent = $digest->send(CarbonImmutable::now(config('support.sla.timezone'))->startOfDay(), (bool) $this->option('force'));

        $this->info($sent === 0 ? 'Nothing sent (already sent this week, or no recipients).' : "Digest queued for {$sent} recipient(s).");

        return self::SUCCESS;
    }
}
