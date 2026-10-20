<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReportFilterData;
use App\DTOs\TicketFilterData;
use App\Enums\ReportTab;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Exceptions\DataToolException;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * The read-only lookups the staff data assistant may ask for (Step 24b).
 *
 * Safety comes from *where* each lookup runs, not from the prompt:
 *  - every lookup runs as the person asking, through the same services as
 *    the Tickets and Reports pages — TicketService::paginate() applies the
 *    visibleTo() scope (an agent sees their own queue + unassigned), and the
 *    report lookup checks the same `viewReportTab` gate as the Reports page;
 *  - rows carry list fields only (number, subject, status, priority, dates,
 *    category, assignee): never descriptions, replies or internal notes;
 *  - there is nothing here that writes.
 *
 * The model's input is untrusted: it is validated like a request, and a bad
 * input becomes an error result the model can correct, not an exception.
 */
class DataAssistantTools
{
    // Same cap as the Reports page date range (ReportFilterRequest).
    private const MAX_REPORT_DAYS = 366;

    private const DATE = 'date_format:Y-m-d';

    public function __construct(
        private readonly TicketService $tickets,
        private readonly ReportService $reports,
        private readonly DashboardService $dashboard,
        private readonly Gate $gate,
        private readonly ValidatorFactory $validator,
    ) {}

    /**
     * Tool definitions in the Messages API shape.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        $date = ['type' => 'string', 'description' => 'YYYY-MM-DD, business-timezone calendar day'];

        return [
            [
                'name' => 'search_tickets',
                'description' => 'List tickets the user can see, newest created first, with the total that match. '
                    .'Use for "latest tickets", "open critical tickets", "tickets created last week".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'tab' => ['type' => 'string', 'enum' => ['active', 'resolved'], 'description' => 'active = open/in progress/waiting; resolved = resolved/closed'],
                        'status' => ['type' => 'string', 'enum' => array_column(TicketStatus::cases(), 'value')],
                        'priority' => ['type' => 'string', 'enum' => array_column(TicketPriority::cases(), 'value')],
                        'created_from' => $date,
                        'created_to' => $date,
                        'keyword' => ['type' => 'string', 'description' => 'words in the subject or description'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $this->maxRows()],
                    ],
                    'required' => ['tab'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'resolved_tickets',
                'description' => 'Tickets resolved between two days (inclusive), most recent resolution first, with the total. '
                    .'Use for "resolved today", "what did we resolve this week".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => $date,
                        'to' => $date,
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $this->maxRows()],
                    ],
                    'required' => ['from', 'to'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'current_summary',
                'description' => 'Live counts right now, as on the dashboard: open, unassigned, critical, SLA breached/approaching, '
                    .'resolved, and per priority. Scoped to what the user can see.',
                'input_schema' => ['type' => 'object', 'properties' => new stdClass, 'additionalProperties' => false],
            ],
            [
                'name' => 'ticket_report',
                'description' => 'Report numbers for a period, as on the Reports page. overview = created/resolved per day; '
                    .'sla = SLA compliance by priority; agents = per-agent performance; aging = open ticket ages; '
                    .'categories = per-category volume. Use for "last 7 days report", "SLA this month".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'report' => ['type' => 'string', 'enum' => ['overview', 'sla', 'agents', 'aging', 'categories']],
                        'from' => $date,
                        'to' => $date,
                    ],
                    'required' => ['report', 'from', 'to'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws DataToolException for unknown lookups, invalid input or a report the user can't see
     */
    public function run(string $name, array $input, User $user): array
    {
        return match ($name) {
            'search_tickets' => $this->searchTickets($input, $user),
            'resolved_tickets' => $this->resolvedTickets($input, $user),
            'current_summary' => $this->currentSummary($user),
            'ticket_report' => $this->ticketReport($input, $user),
            default => throw new DataToolException("Unknown lookup \"{$name}\"."),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function searchTickets(array $input, User $user): array
    {
        $data = $this->validate($input, [
            'tab' => ['required', Rule::in(['active', 'resolved'])],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'created_from' => ['nullable', self::DATE],
            // No after_or_equal: with created_from absent it would compare against the literal text.
            'created_to' => ['nullable', self::DATE],
            'keyword' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$this->maxRows()],
        ]);

        $page = $this->tickets->paginate($user, $data['tab'], new TicketFilterData(
            keyword: $data['keyword'] ?? null,
            status: isset($data['status']) ? TicketStatus::from($data['status']) : null,
            priority: isset($data['priority']) ? TicketPriority::from($data['priority']) : null,
            createdFrom: isset($data['created_from']) ? $this->day($data['created_from'])->utc() : null,
            createdTo: isset($data['created_to']) ? $this->day($data['created_to'])->endOfDay()->utc() : null,
        ), (int) ($data['limit'] ?? 10));

        return [
            'total' => $page->total(),
            'tickets' => collect($page->items())->map(fn (Ticket $t) => $this->row($t))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function resolvedTickets(array $input, User $user): array
    {
        $data = $this->validate($input, [
            'from' => ['required', self::DATE],
            'to' => ['required', self::DATE, 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$this->maxRows()],
        ]);

        $result = $this->tickets->resolvedBetween(
            $user,
            $this->day($data['from'])->utc(),
            $this->day($data['to'])->addDay()->utc(),
            (int) ($data['limit'] ?? 10),
        );

        return [
            'total' => $result['total'],
            'tickets' => $result['tickets']->map(fn (Ticket $t) => [
                ...$this->row($t),
                'resolved_at' => $this->local($t->resolved_at),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSummary(User $user): array
    {
        $dashboard = $this->dashboard->forUser($user);

        return [
            'scope' => $dashboard['scope'],
            'counts' => $dashboard['stats'],
            'by_priority' => $dashboard['byPriority'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function ticketReport(array $input, User $user): array
    {
        $data = $this->validate($input, [
            'report' => ['required', Rule::in(['overview', 'sla', 'agents', 'aging', 'categories'])],
            'from' => ['required', self::DATE],
            'to' => ['required', self::DATE, 'after_or_equal:from'],
        ]);

        $tab = ReportTab::from($data['report']);

        if (! $this->gate->forUser($user)->allows('viewReportTab', [$tab])) {
            throw new DataToolException("This user can't see the {$tab->value} report.");
        }

        $from = $this->day($data['from']);
        $to = $this->day($data['to']);

        if ($from->diffInDays($to) + 1 > self::MAX_REPORT_DAYS) {
            throw new DataToolException('A report covers at most '.self::MAX_REPORT_DAYS.' days.');
        }

        return [
            'report' => $tab->value,
            'from' => $data['from'],
            'to' => $data['to'],
            'data' => $this->reports->forTab($tab, $user, new ReportFilterData($from, $to)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Ticket $ticket): array
    {
        return [
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'category' => $ticket->category?->name,
            'assigned_agent' => $ticket->assignedAgent?->name,
            'created_at' => $this->local($ticket->created_at),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $input, array $rules): array
    {
        $validator = $this->validator->make($input, $rules);

        if ($validator->fails()) {
            throw new DataToolException('Invalid input: '.implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, $this->timezone())->startOfDay();
    }

    private function local(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone($this->timezone())->format('Y-m-d H:i');
    }

    private function timezone(): string
    {
        return (string) config('support.sla.timezone');
    }

    private function maxRows(): int
    {
        return (int) config('ai.data_assistant.max_rows');
    }
}
