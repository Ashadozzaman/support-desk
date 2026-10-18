<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\AgentPerformanceFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class AgentPerformanceFilterRequest extends FormRequest
{
    /**
     * Authorization is checked in the controller via UserPolicy; this
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Picked dates are calendar days in the business timezone (an admin's
     * "today" is Dhaka's today, not UTC's), converted to UTC instants to
     * match the stored `resolved_at`. Defaults to today.
     */
    public function toData(): AgentPerformanceFilterData
    {
        [$from, $to] = $this->dates();
        $timezone = config('support.sla.timezone');

        return new AgentPerformanceFilterData(
            completedFrom: CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
            completedTo: CarbonImmutable::parse($to, $timezone)->endOfDay()->utc(),
        );
    }

    /**
     * The effective (defaulted) dates, echoed back to the page so the
     * date inputs show what the numbers are actually for.
     *
     * @return array{0: string, 1: string}
     */
    public function dates(): array
    {
        $today = $this->today();
        $from = $this->filled('from') ? $this->string('from')->value() : $today;
        $to = $this->filled('to') ? $this->string('to')->value() : max($from, $today);

        return [$from, $to];
    }

    public function today(): string
    {
        return CarbonImmutable::now(config('support.sla.timezone'))->toDateString();
    }

    public function perPage(): int
    {
        return min($this->integer('per_page') ?: 15, 50);
    }
}
