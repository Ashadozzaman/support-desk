# Support Desk — Coding Structure

Folder tree, per-layer templates, and React component rules. Enforces the layering in [`ARCHITECTURE.md`](ARCHITECTURE.md) §1 at the filesystem level — if a class doesn't fit one of these folders, it's probably in the wrong layer.

---

## 1. Backend folder tree (`app/`)

```
app/
├── Console/
│   └── Commands/                  SlaScan.php, AutoAssignTickets.php, WarmDashboardCache.php,
│                                  ReportsBackfill.php, ReportsAggregate.php, ReportsSnapshot.php,
│                                  ReportsWeeklyDigest.php, SyncMailbox.php
├── Contracts/
│   ├── Repositories/               TicketRepositoryInterface, ReplyRepositoryInterface,
│   │                               AttachmentRepositoryInterface, AgentRepositoryInterface,
│   │                               ActivityRepositoryInterface, ImportRepositoryInterface,
│   │                               DashboardRepositoryInterface, SlaConfigRepositoryInterface,
│   │                               UserRepositoryInterface, RoleRepositoryInterface,
│   │                               KnowledgeBaseRepositoryInterface, AiInteractionRepositoryInterface,
│   │                               ChatRepositoryInterface, InboundMessageRepositoryInterface,
│   │                               MailboxRepositoryInterface
│   ├── Assignment/                 AssignmentStrategy
│   ├── Inbound/                    MailboxClient (Step 25b)
│   └── Ai/                         AiClient (Step 22)
├── DTOs/
│   ├── CreateTicketData.php        readonly, constructed from FormRequest::toData()
│   ├── UpdateTicketData.php
│   ├── TicketFilterData.php
│   └── ...                         one per service-method input shape
├── Enums/
│   ├── TicketStatus.php            canTransitionTo()
│   ├── TicketPriority.php
│   ├── ReplyType.php
│   ├── ActivityEvent.php
│   ├── ImportStatus.php
│   ├── SlaState.php
│   └── Role.php                    the 3 built-in role names (seeding, sign-up, protected in the editor) — never used to authorize
├── Events/                         TicketCreated, TicketAssigned, ReplyAdded, InternalNoteAdded,
│                                   TicketStatusChanged, TicketResolved, SlaWarningTriggered, SlaBreached,
│                                   ImportProgressUpdated
├── Exceptions/                     TicketAlreadyAssignedException (409), InvalidStatusTransitionException (422)
├── Http/
│   ├── Controllers/                thin: FormRequest → authorize → one service call → Inertia/redirect
│   │   └── Api/V1/                 REST API twins (TicketController, ReplyController, TokenController) → JSON Resources
│   ├── Requests/                   StoreTicketRequest, UpdateTicketRequest, TicketFilterRequest,
│   │                               StoreReplyRequest (incl. the attachment rules), UpdateTicketStatusRequest,
│   │                               AssignTicketRequest, Admin/*, Api/*, ...
│   └── Resources/                  TicketResource, TicketListResource, ReplyResource, ActivityResource, ...
├── Jobs/                           AutoAssignTicketJob, ProcessSlaWarningJob, ProcessSlaBreachJob (the escalation
│                                   itself: atomic flag UPDATE + activity in one transaction), ProcessTicketImportJob,
│                                   WarmDashboardCacheJob, GenerateReportExportJob, SyncMailboxJob, ProcessInboundEmailJob
├── Listeners/                      queued listeners for the events above
├── Models/                         Eloquent models + relationships, casts, scopes
├── Notifications/                  TicketCreated, TicketAssigned, ReplyReceived, TicketResolved,
│                                   SlaWarning, SlaBreached, ReportExportReady, WeeklyReportDigest, JobFailedAlert
│                                   (ShouldQueue, via mail/database/broadcast)
├── Policies/                       TicketPolicy, ReplyPolicy, AttachmentPolicy, ImportPolicy, UserPolicy, RolePolicy,
│                                   SlaPolicyPolicy, BusinessHourPolicy, HolidayPolicy, ReportPolicy, ReportExportPolicy,
│                                   AuditLogPolicy, MonitoringPolicy, KbArticlePolicy, MailboxSettingPolicy
├── Providers/
│   └── RepositoryServiceProvider.php   binds every Contracts\Repositories\* interface
├── Repositories/
│   └── Eloquent/                   TicketRepository, ReplyRepository, AttachmentRepository,
│                                   AgentRepository, ActivityRepository, ImportRepository,
│                                   DashboardRepository, SlaConfigRepository, UserRepository,
│                                   RoleRepository, KnowledgeBaseRepository, AiInteractionRepository,
│                                   ChatRepository, InboundMessageRepository, MailboxRepository
├── Services/
│   ├── TicketService.php
│   ├── TicketAssignmentService.php
│   ├── ReplyService.php
│   ├── AttachmentService.php
│   ├── SlaService.php
│   ├── ActivityLogService.php
│   ├── DashboardService.php
│   ├── AgentPerformanceService.php
│   ├── TicketMetricsService.php     report metrics on the ticket (response/resolution minutes, sla_met, reopens)
│   ├── ReportAggregationService.php recompute ticket_daily_stats / take ticket_daily_snapshots
│   ├── ReportExportService.php      report CSV export (streamed, or queued above the sync limit)
│   ├── ReportDigestService.php      weekly digest email
│   ├── NotificationService.php      the signed-in user's notification bell: list, unread count, mark read
│   ├── ReportService.php            report read side (overview, SLA, response/resolution, agents, aging, categories)
│   ├── SystemHealthService.php      runs the registered health checks on demand (Step 19b)
│   ├── AuditLogService.php          security/admin audit trail in activity_log (Step 19c)
│   ├── UserManagementService.php    admin users & agents: create/edit, agent profile, lockout rules (Step 19d)
│   ├── RoleService.php              roles editor: create/edit/delete roles, protected roles, lockout (Step 19e)
│   ├── ApiTokenService.php          REST API: issue/revoke Sanctum tokens (same rules as login, incl. 2FA)
│   ├── KnowledgeBaseService.php     help-centre articles: admin CRUD, help-centre list, chatbot retrieval (Step 22)
│   ├── AiGuard.php                  the only caller of AiClient: flag, breaker, rate limits, budget, PII redaction (Step 22)
│   ├── AiInteractionLogger.php      ai_interactions rows + `ai` log channel, never prompt text (Step 22)
│   ├── SupportChatService.php       customer chatbot: KB-grounded answers, hand-off to a ticket (Step 24)
│   ├── DataAssistantService.php     staff data assistant: tool-use loop (Step 24b)
│   ├── DataAssistantTools.php       its read-only lookups, run as the asking user (Step 24b)
│   ├── InboundMessageService.php    any channel's message → ticket or public reply, deduped + threaded (Step 25a)
│   ├── MailboxSettingsService.php   Admin → Mailbox: save (write-only password), test connection (Step 25b)
│   ├── MailboxSyncService.php       fetch new mail → spool → queue one job per message, sync history (Step 25b)
│   ├── TicketEmailThreadService.php thread headers on customer ticket mail (Step 25c)
│   └── TicketImportService.php
└── Support/
    ├── Tickets/TicketNumberGenerator.php
    ├── Sla/BusinessHoursCalculator.php
    ├── TrendSeries.php              shared day-series zero-fill (DashboardService + AgentPerformanceService)
    ├── Percentile.php               nearest-rank percentile from a frequency table (ReportService)
    ├── Logging/LogRedactor.php      email HMAC, redacted exception messages (Step 19a)
    ├── Health/                      SlaBacklogCheck, QueueBacklogCheck, FailedJobsCheck, ReverbCheck (Step 19b), AiProviderCheck (Step 22)
    ├── Ai/                          AnthropicClient, OpenAiClient, FakeAiClient, PiiRedactor (Step 22/24b)
    ├── Inbound/                     WebklexMailboxClient, FakeMailboxClient, EmailMessageParser (Step 25b), TicketMessageId (25c)
    └── Assignment/
        ├── LeastWorkloadStrategy.php
        └── RoundRobinStrategy.php
```

---

## 2. Frontend folder tree (`resources/js/`)

```
resources/js/
├── components/
│   ├── form/        TextInput, TextAreaInput, SelectInput, PrioritySelect, CategorySelect,
│   │                StatusSelect, AgentSelect, DateInput, DateRangeInput, MonthInput, FileUploadInput,
│   │                CheckboxInput, CheckboxGroupInput,
│   │                SearchInput (debounced), SubmitButton
│   ├── cards/       FormCard, StatCard, FilterCard, TicketCard, ReplyCard, AttachmentCard,
│   │                AgentWorkloadCard, ReportFilterCard, KbArticleCard, AskAssistantCard
│   ├── ticket/      StatusBadge, PriorityBadge, SlaBadge, TicketForm, TicketTable,
│   │                TicketFilters, ClaimTicketButton, AssignAgentDialog
│   ├── agent/       AgentPerformanceTable, AgentStatusBreakdownCard, AgentDailyTable
│   ├── charts/      PriorityBarChart, TicketsTrendChart, TrendChart (multi-series), SimpleBarChart
│   ├── report/      ReportTabs, one view per tab (Overview/Sla/ResponseResolution/Agents/Aging/Categories)
│   ├── activity/    ActivityTimeline, ActivityItemCard
│   ├── audit/       AuditLogTable
│   ├── user/        UserForm (shared create/edit), UserTable
│   ├── role/        RoleForm (shared create/edit, grouped permission checkboxes), RoleTable
│   ├── kb/          KbArticleForm (shared create/edit), KbArticleTable
│   ├── chat/        ChatWidget, ChatMessageItem (Step 24)
│   ├── reply/       ReplyThread, ReplyForm
│   ├── data/        Pagination, EmptyState, DataTable
│   └── feedback/    FlashMessage, ConfirmDialog
├── hooks/           useCan, useNotifications, useTicketChannel, useStaffQueue, usePolling
├── layouts/         AppLayout, GuestLayout (from the React starter kit)
├── pages/
│   ├── tickets/     index.tsx, create.tsx, edit.tsx, show.tsx
│   ├── imports/     index.tsx, create.tsx, show.tsx
│   ├── dashboard/   index.tsx
│   ├── reports/     index.tsx
│   ├── help/        index.tsx, show.tsx (help centre, Step 22), chat.tsx (Step 24)
│   ├── assistant/   index.tsx (staff data assistant, Step 24b)
│   └── admin/       kb-articles (index/create/edit), sla-policies, business-hours, agents (index.tsx, show.tsx), audit-log, users (index/create/edit), roles (index/create/edit), categories
└── types/           ticket.ts, reply.ts, activity.ts, user.ts, pagination.ts, dashboard.ts, agent.ts, ...
```

---

## 3. Layer templates

### Controller
```php
class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $ticket = $this->tickets->create($request->toData(), $request->user());

        return redirect()->route('tickets.show', $ticket->ticket_number);
    }
}
```
No queries, no `DB::transaction`, no business rules. One service call per action.

### FormRequest with `toData()`
```php
class StoreTicketRequest extends FormRequest
{
    public function rules(): array { /* ... */ }

    public function toData(): CreateTicketData
    {
        return new CreateTicketData(
            subject: $this->string('subject')->value(),
            description: $this->string('description')->value(),
            priority: TicketPriority::from($this->string('priority')->value()),
            categoryId: $this->integer('category_id'),
            createdBy: $this->user()->id,
        );
    }
}
```

### Service
```php
class TicketService
{
    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly SlaService $sla,
        private readonly ActivityLogService $activity,
    ) {}

    public function create(CreateTicketData $data): Ticket
    {
        return DB::transaction(function () use ($data) {
            [$dueAt, $warningAt] = $this->sla->calculate($data->priority, now());

            $ticket = $this->tickets->create($data, $dueAt, $warningAt); // number generated LATE, inside here

            $this->activity->log($ticket, ActivityEvent::TicketCreated, actor: null, old: [], new: $ticket->toArray());

            return $ticket;
        }, attempts: 3);
    }
}
```
Opens the transaction, calls repositories/other services, logs activity in the same transaction, dispatches events only `afterCommit`.

### Repository
```php
class TicketRepository implements TicketRepositoryInterface
{
    public function create(CreateTicketData $data, CarbonImmutable $dueAt, CarbonImmutable $warningAt): Ticket
    {
        return Ticket::create([
            'ticket_number' => app(TicketNumberGenerator::class)->next(),
            'subject' => $data->subject,
            'description' => $data->description,
            'priority' => $data->priority,
            'category_id' => $data->categoryId,
            'created_by' => $data->createdBy,
            'status' => TicketStatus::Open,
            'sla_due_at' => $dueAt,
            'sla_warning_at' => $warningAt,
        ]);
    }

    public function paginateFor(User $viewer, TicketFilterData $filters): LengthAwarePaginator
    {
        return Ticket::query()
            ->visibleTo($viewer)
            ->filter($filters)
            ->with(['category:id,name', 'assignedAgent:id,name', 'creator:id,name'])
            ->select(['id', 'ticket_number', 'subject', 'status', 'priority', 'category_id', 'assigned_agent_id', 'created_by', 'sla_due_at', 'sla_warning_at', 'created_at'])
            ->latest()
            ->paginate(15)
            ->withQueryString();
    }
}
```
No events, no authorization, no business decisions, never opens its own transaction — it runs inside whatever transaction the calling service started.

### DTO
```php
readonly class CreateTicketData
{
    public function __construct(
        public string $subject,
        public string $description,
        public TicketPriority $priority,
        public ?int $categoryId,
        public int $createdBy,
    ) {}
}
```

### Enum
```php
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Open => in_array($target, [self::InProgress, self::Resolved], true),
            self::InProgress => in_array($target, [self::WaitingForCustomer, self::Resolved], true),
            self::WaitingForCustomer => in_array($target, [self::InProgress, self::Resolved], true),
            self::Resolved => $target === self::Closed || $target === self::Open, // customer reply reopens
            self::Closed => false,
        };
    }
}
```

### Policy
```php
class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->can('tickets.view-any')) {
            return true;
        }
        if ($user->can('tickets.view-queue')) {
            return $ticket->assigned_agent_id === $user->id || $ticket->assigned_agent_id === null;
        }
        return $user->can('tickets.view-own') && $ticket->created_by === $user->id;
    }
}
```
Always checks a **permission** first, then ownership/assignment — never `$user->hasRole('agent')` directly.

---

## 4. React component rules

* **No raw `<input>/<select>/<textarea>`** and no hand-rolled card markup inside a `pages/*` file — always go through `components/form/*` / `components/cards/*`. If a new shape is needed, add a component there rather than writing markup in the page.
* Every form uses `FormCard` + Inertia `useForm()`; each field receives `error={form.errors.fieldName}` from the field's own `error` prop — never a page-level error block.
* `tickets/create` and `tickets/edit` render the **same** `TicketForm`, parameterised by mode.
* Status/priority/SLA are **always** shown via `StatusBadge` / `PriorityBadge` / `SlaBadge` — never inline conditional styling in a page.
* TypeScript strict, no `any`. Shared shapes live in `resources/js/types/*.ts` and are imported, not redeclared per-page.
* User-authored text (ticket body, replies, notes) renders as **plain text** — never `dangerouslySetInnerHTML`.
* Responsive: `TicketTable` renders a table ≥ `md`, falls back to a stacked `TicketCard` list below it.

---

## 5. Binding convention

Every interface in `app/Contracts/Repositories/*` is bound to its `app/Repositories/Eloquent/*` implementation in `RepositoryServiceProvider::register()`:

```php
public function register(): void
{
    $this->app->bind(TicketRepositoryInterface::class, TicketRepository::class);
    $this->app->bind(ReplyRepositoryInterface::class, ReplyRepository::class);
    // ...
}
```

`AssignmentStrategy` is bound conditionally based on `config('support.assignment.strategy')` (a simple `match` in the same provider), not hard-coded to `LeastWorkloadStrategy`.
