# Support Desk — Coding Structure

Folder tree, per-layer templates, and React component rules. Enforces the layering in [`ARCHITECTURE.md`](ARCHITECTURE.md) §1 at the filesystem level — if a class doesn't fit one of these folders, it's probably in the wrong layer.

---

## 1. Backend folder tree (`app/`)

```
app/
├── Console/
│   └── Commands/                  SlaScan.php, AutoAssignTickets.php, AutoCloseTickets.php (P2)
├── Contracts/
│   ├── Repositories/               TicketRepositoryInterface, ReplyRepositoryInterface,
│   │                               AttachmentRepositoryInterface, AgentRepositoryInterface,
│   │                               ActivityRepositoryInterface, ImportRepositoryInterface,
│   │                               DashboardRepositoryInterface, SlaConfigRepositoryInterface
│   └── Assignment/                 AssignmentStrategy
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
│   └── Role.php                    string source for spatie role names only — not a DB column
├── Events/                         TicketCreated, TicketAssigned, ReplyAdded, InternalNoteAdded,
│                                   TicketStatusChanged, TicketResolved, SlaWarningTriggered, SlaBreached,
│                                   ImportProgressUpdated
├── Exceptions/                     TicketAlreadyAssignedException (409), InvalidStatusTransitionException (422)
├── Http/
│   ├── Controllers/                thin: FormRequest → authorize → one service call → Inertia/redirect
│   ├── Requests/                   StoreTicketRequest, UpdateTicketRequest, TicketFilterRequest,
│   │                               StoreReplyRequest, StoreAttachmentRequest, ...
│   └── Resources/                  TicketResource, TicketListResource, ReplyResource, ActivityResource, ...
├── Jobs/                           AutoAssignTicketJob, ProcessSlaWarningJob, ProcessSlaBreachJob,
│                                   ProcessTicketImportJob
├── Listeners/                      queued listeners for the events above
├── Models/                         Eloquent models + relationships, casts, scopes
├── Notifications/                  TicketCreated, TicketAssigned, ReplyReceived, TicketResolved,
│                                   SlaWarning, SlaBreached  (ShouldQueue, via mail/database/broadcast)
├── Policies/                       TicketPolicy, ReplyPolicy, AttachmentPolicy, ImportPolicy
├── Providers/
│   └── RepositoryServiceProvider.php   binds every Contracts\Repositories\* interface
├── Repositories/
│   └── Eloquent/                   TicketRepository, ReplyRepository, AttachmentRepository,
│                                   AgentRepository, ActivityRepository, ImportRepository,
│                                   DashboardRepository, SlaConfigRepository
├── Services/
│   ├── TicketService.php
│   ├── TicketAssignmentService.php
│   ├── ReplyService.php
│   ├── AttachmentService.php
│   ├── SlaService.php
│   ├── EscalationService.php
│   ├── ActivityLogService.php
│   ├── DashboardService.php
│   └── TicketImportService.php
└── Support/
    ├── Tickets/TicketNumberGenerator.php
    ├── Sla/BusinessHoursCalculator.php
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
│   │                StatusSelect, AgentSelect, DateInput, DateRangeInput, FileUploadInput,
│   │                SearchInput (debounced), SubmitButton
│   ├── cards/       FormCard, StatCard, FilterCard, TicketCard, ReplyCard, AttachmentCard
│   ├── ticket/      StatusBadge, PriorityBadge, SlaBadge, TicketForm, TicketTable,
│   │                TicketFilters, ClaimTicketButton, AssignAgentDialog
│   ├── activity/    ActivityTimeline, ActivityItemCard
│   ├── reply/       ReplyThread, ReplyForm
│   ├── data/        Pagination, EmptyState
│   └── feedback/    FlashMessage, ConfirmDialog
├── hooks/           useCan, useNotifications, useTicketChannel, useStaffQueue, usePolling
├── layouts/         AppLayout, GuestLayout (from the React starter kit)
├── pages/
│   ├── tickets/     index.tsx, create.tsx, edit.tsx, show.tsx
│   ├── imports/     index.tsx, create.tsx, show.tsx
│   ├── dashboard/   index.tsx
│   └── admin/       sla-policies, business-hours, categories, users
└── types/           ticket.ts, reply.ts, activity.ts, user.ts, pagination.ts, ...
```

---

## 3. Layer templates

### Controller
```php
final class TicketController extends Controller
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
final class StoreTicketRequest extends FormRequest
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
final class TicketService
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
final class TicketRepository implements TicketRepositoryInterface
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
final readonly class CreateTicketData
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
final class TicketPolicy
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
