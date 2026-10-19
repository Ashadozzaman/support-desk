<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\AuditLogFilterData;
use App\Enums\ActivityEvent;
use App\Enums\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditLogFilterRequest extends FormRequest
{
    /**
     * Authorization is checked in the controller (viewAuditLog); this
     * request only validates query shape.
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
            'log' => ['nullable', Rule::in(AuditEvent::logNames())],
            'event' => ['nullable', Rule::in([
                ...array_column(AuditEvent::cases(), 'value'),
                ...array_column(ActivityEvent::cases(), 'value'),
            ])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Picked dates are business-timezone calendar days, converted to UTC
     * bounds to match the stored created_at (same rule as the Agents page).
     */
    public function toData(): AuditLogFilterData
    {
        [$from, $to] = $this->dates();
        $timezone = config('support.sla.timezone');

        return new AuditLogFilterData(
            logName: $this->filled('log') ? $this->string('log')->value() : null,
            event: AuditEvent::tryFrom($this->string('event')->value()),
            ticketEvent: ActivityEvent::tryFrom($this->string('event')->value()),
            from: CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
            to: CarbonImmutable::parse($to, $timezone)->endOfDay()->utc(),
        );
    }

    /**
     * Always a bounded window — default the last support.audit.page_default_days
     * days. Older entries are reached by picking earlier dates; nothing is
     * moved. Bounding it also keeps the page's total COUNT to that range
     * instead of the whole table.
     *
     * @return array{0: string, 1: string}
     */
    public function dates(): array
    {
        $today = $this->today();
        $defaultFrom = CarbonImmutable::parse($today)->subDays(config('support.audit.page_default_days') - 1)->toDateString();

        $from = $this->filled('from') ? $this->string('from')->value() : $defaultFrom;
        $to = $this->filled('to') ? $this->string('to')->value() : max($from, $today);

        return [$from, $to];
    }

    public function today(): string
    {
        return CarbonImmutable::now(config('support.sla.timezone'))->toDateString();
    }

    /**
     * The effective (defaulted) filters, echoed back so the inputs show
     * what the list is actually for.
     *
     * @return array{log: ?string, event: ?string, from: string, to: string}
     */
    public function filters(): array
    {
        [$from, $to] = $this->dates();

        return [
            'log' => $this->input('log'),
            'event' => $this->input('event'),
            'from' => $from,
            'to' => $to,
        ];
    }
}
