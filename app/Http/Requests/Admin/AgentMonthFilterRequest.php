<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\AgentMonthFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class AgentMonthFilterRequest extends FormRequest
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
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /**
     * The picked month is a business-timezone calendar month (an admin's
     * "October" is Dhaka's October). Defaults to the current month.
     */
    public function toData(): AgentMonthFilterData
    {
        $timezone = config('support.sla.timezone');
        $now = CarbonImmutable::now($timezone);

        $start = $this->filled('month')
            ? CarbonImmutable::createFromFormat('!Y-m', $this->string('month')->value(), $timezone)
            : $now->startOfMonth();

        return new AgentMonthFilterData(
            start: $start,
            end: $start->endOfMonth()->min($now),
        );
    }
}
