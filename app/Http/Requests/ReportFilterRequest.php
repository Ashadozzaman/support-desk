<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\ReportFilterData;
use App\Enums\Permission;
use App\Enums\ReportGranularity;
use App\Enums\ReportTab;
use App\Enums\TicketPriority;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportFilterRequest extends FormRequest
{
    /**
     * A year is enough for any report and keeps the rollup read bounded.
     */
    public const MAX_DAYS = 366;

    /**
     * Default window when no dates are picked.
     */
    private const DEFAULT_DAYS = 30;

    /**
     * Authorization is the controller's job (Step 18, reports.* permissions);
     * this request only validates query shape.
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
            'tab' => ['nullable', Rule::enum(ReportTab::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'granularity' => ['nullable', Rule::enum(ReportGranularity::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                [$from, $to] = $this->dates();

                if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('to', 'The report range may not exceed '.self::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function toData(): ReportFilterData
    {
        [$from, $to] = $this->dates();

        return new ReportFilterData(
            from: $from,
            to: $to,
            granularity: $this->enum('granularity', ReportGranularity::class) ?? ReportGranularity::Day,
            priority: $this->enum('priority', TicketPriority::class),
            categoryId: $this->filled('category_id') ? $this->integer('category_id') : null,
            agentId: $this->filled('agent_id') ? $this->integer('agent_id') : null,
        );
    }

    /**
     * Default tab: Overview for admins, the agent's own row otherwise.
     */
    public function tab(): ReportTab
    {
        return $this->enum('tab', ReportTab::class)
            ?? ($this->user()?->can(Permission::ReportsView->value) ? ReportTab::Overview : ReportTab::Agents);
    }

    /**
     * The effective (defaulted) filters, echoed back so the page's inputs
     * show what the numbers are actually for.
     *
     * @return array{from: string, to: string, granularity: string, priority: ?string, category_id: ?int, agent_id: ?int}
     */
    public function effectiveFilters(): array
    {
        $data = $this->toData();

        return [
            'from' => $data->from->toDateString(),
            'to' => $data->to->toDateString(),
            'granularity' => $data->granularity->value,
            'priority' => $data->priority?->value,
            'category_id' => $data->categoryId,
            'agent_id' => $data->agentId,
        ];
    }

    public function today(): string
    {
        return CarbonImmutable::now(config('support.sla.timezone'))->toDateString();
    }

    /**
     * Picked dates are business-timezone calendar days; default is the last
     * 30 days ending today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dates(): array
    {
        $timezone = config('support.sla.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $to = $this->filled('to') ? CarbonImmutable::parse($this->string('to')->value(), $timezone) : $today;
        $from = $this->filled('from')
            ? CarbonImmutable::parse($this->string('from')->value(), $timezone)
            : $to->subDays(self::DEFAULT_DAYS - 1);

        return [$from, $to];
    }
}
