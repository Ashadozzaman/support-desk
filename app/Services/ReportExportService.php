<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ReportExportRepositoryInterface;
use App\Contracts\Repositories\ReportRepositoryInterface;
use App\DTOs\ReportFilterData;
use App\DTOs\TicketExportPlan;
use App\Enums\ReportExportStatus;
use App\Enums\ReportTab;
use App\Jobs\GenerateReportExportJob;
use App\Models\ReportExport;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ReportExportReady;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\LazyCollection;

/**
 * CSV exports (ENHANCEMENTS.md §1.4). Two kinds:
 *  - summary: the numbers of one report tab — small, always streamed;
 *  - tickets: one row per ticket created in the period, with its report
 *    metrics. Up to `support.reports.export_sync_limit` rows stream straight
 *    to the browser; above that it's generated on the `exports` queue and
 *    the user gets a signed, expiring download link.
 * Every cell goes through Csv::safeCell() (CSV injection).
 */
class ReportExportService
{
    public const TICKET_HEADER = [
        'Ticket', 'Created', 'Status', 'Priority', 'Category', 'Agent',
        'First response (business min)', 'Resolved', 'Resolution (business min)', 'SLA met', 'Reopened',
    ];

    public function __construct(
        private readonly ReportService $reportService,
        private readonly ReportRepositoryInterface $reports,
        private readonly ReportExportRepositoryInterface $exports,
    ) {}

    /**
     * @return array{filename: string, rows: list<array<int, string|int|float|null>>} header row first
     */
    public function summary(ReportTab $tab, User $viewer, ReportFilterData $filters): array
    {
        $report = $this->reportService->forTab($tab, $viewer, $filters);
        $period = "{$filters->from->toDateString()}_{$filters->to->toDateString()}";

        $rows = match ($tab) {
            ReportTab::Overview => [
                ['Period start', 'Created', 'Resolved'],
                ...array_map(fn (array $p) => [$p['bucket'], $p['created'], $p['resolved']], $report['series']),
            ],
            ReportTab::Sla => [
                ['Priority', 'Within SLA', 'Late', 'Compliance %'],
                ...array_map(fn (array $p) => [$p['priority'], $p['met'], $p['breached'], $p['compliance_pct']], $report['byPriority']),
                ['All', $report['totals']['met'], $report['totals']['breached'], $report['totals']['compliance_pct']],
            ],
            ReportTab::ResponseResolution => [
                ['Metric', 'Priority', 'Average (business min)', 'Median', '90th percentile', 'Tickets (median/p90)'],
                ...$this->durationRows('First response', $report['firstResponse']),
                ...$this->durationRows('Resolution', $report['resolution']),
            ],
            ReportTab::Agents => [
                ['Agent', 'Resolved', 'SLA met %', 'Avg first response (business min)', 'Avg resolution (business min)', 'Reopen rate %', 'Escalations', 'Active now'],
                ...array_map(fn (array $a) => [
                    $a['name'], $a['resolved'], $a['sla_met_pct'], $a['avg_first_response_minutes'],
                    $a['avg_resolution_minutes'], $a['reopen_rate_pct'], $a['escalated'], $a['active_now'],
                ], $report),
            ],
            ReportTab::Aging => [
                ['Priority', '< 1 day', '1-3 days', '3-7 days', '7+ days', 'Unassigned', 'Oldest unassigned (UTC)'],
                ...array_map(fn (array $p) => [
                    $p['priority'], $p['age_0_1d'], $p['age_1_3d'], $p['age_3_7d'], $p['age_7d_plus'],
                    $p['unassigned_count'], $p['oldest_unassigned_at'],
                ], $report['byPriority']),
            ],
            ReportTab::Categories => [
                ['Category', 'Created', 'Share %', 'Resolved', 'Avg resolution (business min)', 'Breach rate %'],
                ...array_map(fn (array $c) => [
                    $c['name'] ?? 'No category', $c['created'], $c['share_pct'], $c['resolved'],
                    $c['avg_resolution_minutes'], $c['breach_rate_pct'],
                ], $report),
            ],
        };

        return ['filename' => "report-{$tab->value}-{$period}.csv", 'rows' => $rows];
    }

    /**
     * Small result → rows to stream now. Large → an export row + a job
     * dispatched after commit (never for a row that rolled back).
     */
    public function tickets(User $viewer, ReportFilterData $filters): TicketExportPlan
    {
        if ($this->reports->countTicketsForExport($filters) <= (int) config('support.reports.export_sync_limit')) {
            return new TicketExportPlan(rows: $this->ticketRows($filters));
        }

        $export = DB::transaction(function () use ($viewer, $filters) {
            $export = $this->exports->create(
                $viewer->id,
                $filters->toArray(),
                CarbonImmutable::now()->addDays((int) config('support.reports.export_ttl_days')),
            );

            DB::afterCommit(fn () => GenerateReportExportJob::dispatch($export->id));

            return $export;
        });

        return new TicketExportPlan(queued: $export);
    }

    /**
     * Run by GenerateReportExportJob. Idempotent: an export that already
     * completed (e.g. a duplicate delivery of the job) is left alone.
     */
    public function generate(int $exportId): void
    {
        $export = $this->exports->find($exportId);

        if ($export === null || $export->status === ReportExportStatus::Completed) {
            return;
        }

        $this->exports->markStatus($export, ReportExportStatus::Processing);

        $path = "exports/{$export->id}.csv";
        $disk = Storage::disk('local');
        $disk->makeDirectory('exports');

        $handle = Csv::open($disk->path($path));
        Csv::writeRow($handle, self::TICKET_HEADER);
        $count = 0;

        foreach ($this->ticketRows(ReportFilterData::fromArray($export->filters)) as $row) {
            Csv::writeRow($handle, $row);
            $count++;
        }

        fclose($handle);

        DB::transaction(function () use ($export, $path, $count) {
            $this->exports->markCompleted($export, $path, $count);

            DB::afterCommit(fn () => $export->user->notify(new ReportExportReady($export, $this->downloadUrl($export))));
        });
    }

    public function markFailed(int $exportId): void
    {
        $export = $this->exports->find($exportId);

        if ($export !== null) {
            $this->exports->markStatus($export, ReportExportStatus::Failed);
        }
    }

    /**
     * Signed AND expiring with the file itself; the download route still
     * requires the owner to be logged in (ReportExportPolicy).
     */
    public function downloadUrl(ReportExport $export): string
    {
        return URL::temporarySignedRoute('reports.exports.download', $export->expires_at, ['export' => $export->id]);
    }

    /**
     * Data rows (no header) for the ticket export, read lazily.
     *
     * @return LazyCollection<int, array<int, string|int|float|null>>
     */
    public function ticketRows(ReportFilterData $filters): LazyCollection
    {
        $timezone = config('support.sla.timezone');

        return $this->reports->ticketsForExport($filters)->map(fn (Ticket $ticket) => [
            $ticket->ticket_number,
            $ticket->created_at?->setTimezone($timezone)->format('Y-m-d H:i'),
            $ticket->status->value,
            $ticket->priority->value,
            $ticket->category?->name,
            $ticket->assignedAgent?->name,
            $ticket->first_response_minutes,
            $ticket->resolved_at?->setTimezone($timezone)->format('Y-m-d H:i'),
            $ticket->resolution_minutes,
            match ($ticket->sla_met) {
                true => 'yes',
                false => 'no',
                null => '',
            },
            $ticket->reopened_count,
        ]);
    }

    /**
     * @param  array<string, array{avg: ?int, median: ?int, p90: ?int, count: int}>  $breakdown
     * @return list<list<string|int|null>>
     */
    private function durationRows(string $metric, array $breakdown): array
    {
        $rows = [];
        foreach ($breakdown as $priority => $stats) {
            $rows[] = [$metric, $priority, $stats['avg'], $stats['median'], $stats['p90'], $stats['count']];
        }

        return $rows;
    }
}
