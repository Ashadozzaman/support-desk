import { router } from '@inertiajs/react';
import { FilterCard } from '@/components/cards/FilterCard';
import { DateRangeInput } from '@/components/form/DateRangeInput';
import { SelectInput } from '@/components/form/SelectInput';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { daysBefore } from '@/lib/format';
import { index } from '@/routes/reports';
import type { ReportFilters, ReportOptions, ReportTab } from '@/types/report';
import { TICKET_PRIORITY_LABELS } from '@/types/ticket';

const ALL = '__all__';

const GRANULARITY_OPTIONS = [
    { value: 'day', label: 'By day' },
    { value: 'week', label: 'By week' },
    { value: 'month', label: 'By month' },
];

/**
 * Every change is a GET with the filters in the URL (shareable, back-button
 * friendly). Presets use the server's `today` (business timezone), never the
 * browser clock. Priority/category/agent filters only appear for someone who
 * can see across agents.
 */
export function ReportFilterCard({
    tab,
    filters,
    options,
    today,
    errors,
}: {
    tab: ReportTab;
    filters: ReportFilters;
    options: ReportOptions;
    today: string;
    errors: Partial<Record<keyof ReportFilters, string>>;
}) {
    const canViewAll = useCan('reports.view');

    function apply(changes: Partial<ReportFilters>) {
        const query = { ...filters, ...changes, tab };
        router.get(
            index({ query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const presets = [
        { label: 'Last 7 days', from: daysBefore(today, 6) },
        { label: 'Last 30 days', from: daysBefore(today, 29) },
        { label: 'Last 90 days', from: daysBefore(today, 89) },
    ];

    return (
        <FilterCard>
            <DateRangeInput
                fromName="from"
                toName="to"
                label="Period"
                from={filters.from}
                to={filters.to}
                errors={{ from: errors.from, to: errors.to }}
                onChange={(range) =>
                    apply({
                        from: range.from ?? undefined,
                        to: range.to ?? undefined,
                    })
                }
            />
            <div className="flex flex-wrap gap-2">
                {presets.map((preset) => (
                    <Button
                        key={preset.label}
                        type="button"
                        size="sm"
                        variant={
                            filters.from === preset.from && filters.to === today
                                ? 'default'
                                : 'outline'
                        }
                        onClick={() => apply({ from: preset.from, to: today })}
                    >
                        {preset.label}
                    </Button>
                ))}
            </div>
            <div className="w-36">
                <SelectInput
                    name="granularity"
                    label="Group"
                    error={errors.granularity}
                    value={filters.granularity}
                    options={GRANULARITY_OPTIONS}
                    onChange={(value) =>
                        apply({
                            granularity: value as ReportFilters['granularity'],
                        })
                    }
                />
            </div>
            {canViewAll && (
                <>
                    <div className="w-40">
                        <SelectInput
                            name="priority"
                            label="Priority"
                            error={errors.priority}
                            value={filters.priority ?? ALL}
                            options={[
                                { value: ALL, label: 'All priorities' },
                                ...Object.entries(TICKET_PRIORITY_LABELS).map(
                                    ([value, label]) => ({ value, label }),
                                ),
                            ]}
                            onChange={(value) =>
                                apply({
                                    priority:
                                        value === ALL
                                            ? null
                                            : (value as ReportFilters['priority']),
                                })
                            }
                        />
                    </div>
                    <div className="w-48">
                        <SelectInput
                            name="category_id"
                            label="Category"
                            error={errors.category_id}
                            value={
                                filters.category_id === null
                                    ? ALL
                                    : String(filters.category_id)
                            }
                            options={[
                                { value: ALL, label: 'All categories' },
                                ...options.categories.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                })),
                            ]}
                            onChange={(value) =>
                                apply({
                                    category_id:
                                        value === ALL ? null : Number(value),
                                })
                            }
                        />
                    </div>
                    <div className="w-48">
                        <SelectInput
                            name="agent_id"
                            label="Agent"
                            error={errors.agent_id}
                            value={
                                filters.agent_id === null
                                    ? ALL
                                    : String(filters.agent_id)
                            }
                            options={[
                                { value: ALL, label: 'All agents' },
                                ...options.agents.map((a) => ({
                                    value: String(a.id),
                                    label: a.name,
                                })),
                            ]}
                            onChange={(value) =>
                                apply({
                                    agent_id:
                                        value === ALL ? null : Number(value),
                                })
                            }
                        />
                    </div>
                </>
            )}
        </FilterCard>
    );
}
