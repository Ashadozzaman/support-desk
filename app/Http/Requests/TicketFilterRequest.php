<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\TicketFilterData;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketFilterRequest extends FormRequest
{
    /**
     * Authorization for the list itself is checked in the controller
     * (CODING_STRUCTURE.md §3); this request only validates query shape.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab' => ['nullable', 'in:active,resolved'],
            'ticket_number' => ['nullable', 'string', 'max:32'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'sla_state' => ['nullable', Rule::in(SlaState::keys())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toData(): TicketFilterData
    {
        return new TicketFilterData(
            ticketNumber: $this->filled('ticket_number') ? $this->string('ticket_number')->value() : null,
            keyword: $this->filled('keyword') ? $this->string('keyword')->value() : null,
            status: $this->filled('status') ? TicketStatus::from($this->string('status')->value()) : null,
            priority: $this->filled('priority') ? TicketPriority::from($this->string('priority')->value()) : null,
            categoryId: $this->filled('category_id') ? $this->integer('category_id') : null,
            assignedAgentId: $this->filled('assigned_agent_id') ? $this->integer('assigned_agent_id') : null,
            createdBy: $this->filled('created_by') ? $this->integer('created_by') : null,
            createdFrom: $this->filled('created_from')
                ? CarbonImmutable::parse($this->string('created_from')->value())->startOfDay()
                : null,
            createdTo: $this->filled('created_to')
                ? CarbonImmutable::parse($this->string('created_to')->value())->endOfDay()
                : null,
            slaState: $this->filled('sla_state') ? SlaState::fromKey($this->string('sla_state')->value()) : null,
        );
    }

    public function tab(): string
    {
        return $this->query('tab') === 'resolved' ? 'resolved' : 'active';
    }

    public function perPage(): int
    {
        return min($this->integer('per_page') ?: 15, 50);
    }
}
