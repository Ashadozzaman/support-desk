<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReportFilterData;
use App\Enums\Permission;
use App\Models\User;
use App\Notifications\WeeklyReportDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Weekly report email to everyone with reports.view (ENHANCEMENTS.md §1.5):
 * the last 7 complete days against the 7 before, the categories breaching
 * most, and the agents with the most open work — with the numbers attached
 * as CSV. Built from ReportService, so it can't disagree with the page.
 */
class ReportDigestService
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Sends at most once per week window (a Cache::add marker), so a
     * scheduler retry or a manual re-run doesn't email everyone twice;
     * $force overrides that on purpose.
     *
     * @return int how many people it was sent to (0 = already sent)
     */
    public function send(CarbonImmutable $today, bool $force = false): int
    {
        $current = new ReportFilterData($today->subDays(7), $today->subDay());
        $previous = new ReportFilterData($today->subDays(14), $today->subDays(8));

        // Same precedent as TicketAssignmentService::agentOptions(): a direct,
        // read-only user query; there's no UserRepository.
        $recipients = User::permission(Permission::ReportsView->value)->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $digest = $this->build($current, $previous, $recipients->first());

        // Marked only once the numbers are built — a failure above must not
        // silently skip the week. Cache::add is atomic: of two concurrent
        // runs, only one gets to send.
        $marker = "reports.digest.sent.{$current->from->toDateString()}";
        if (! $force && ! Cache::add($marker, true, now()->addDays(8))) {
            return 0;
        }

        Notification::send($recipients, new WeeklyReportDigest($digest));

        return $recipients->count();
    }

    /**
     * @return array{period: array{from: string, to: string}, kpis: list<array{label: string, current: int|float|null, previous: int|float|null}>, topBreachingCategories: list<array{name: string, breach_rate_pct: ?float}>, busiestAgents: list<array{name: ?string, active_now: int}>}
     */
    public function build(ReportFilterData $current, ReportFilterData $previous, User $viewer): array
    {
        $now = $this->reports->overview($current)['totals'];
        $before = $this->reports->overview($previous)['totals'];
        $sla = $this->reports->slaCompliance($current)['totals'];
        $slaBefore = $this->reports->slaCompliance($previous)['totals'];

        $categories = collect($this->reports->categories($current))
            ->filter(fn (array $c) => $c['breach_rate_pct'] !== null)
            ->sortByDesc('breach_rate_pct')
            ->take(3)
            ->map(fn (array $c) => ['name' => $c['name'] ?? 'No category', 'breach_rate_pct' => $c['breach_rate_pct']])
            ->values()
            ->all();

        $agents = collect($this->reports->agentPerformance($viewer, $current))
            ->sortByDesc('active_now')
            ->take(3)
            ->map(fn (array $a) => ['name' => $a['name'], 'active_now' => $a['active_now']])
            ->values()
            ->all();

        return [
            'period' => ['from' => $current->from->toDateString(), 'to' => $current->to->toDateString()],
            'kpis' => [
                ['label' => 'Tickets created', 'current' => $now['created'], 'previous' => $before['created']],
                ['label' => 'Tickets resolved', 'current' => $now['resolved'], 'previous' => $before['resolved']],
                ['label' => 'SLA compliance %', 'current' => $sla['compliance_pct'], 'previous' => $slaBefore['compliance_pct']],
                ['label' => 'Escalations', 'current' => $sla['escalated'], 'previous' => $slaBefore['escalated']],
                ['label' => 'Reopened', 'current' => $now['reopened'], 'previous' => $before['reopened']],
            ],
            'topBreachingCategories' => $categories,
            'busiestAgents' => $agents,
        ];
    }
}
