import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { CheckboxInput } from '@/components/form/CheckboxInput';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextInput } from '@/components/form/TextInput';
import { dashboard } from '@/routes';
import { index, update } from '@/routes/admin/sla-policies';
import { TICKET_PRIORITY_LABELS, type TicketPriorityValue } from '@/types/ticket';

type SlaPolicyRow = {
    priority: TicketPriorityValue;
    resolution_minutes: number;
    warning_threshold_percent: number;
    is_active: boolean;
};

export default function SlaPoliciesIndex({ policies }: { policies: { data: SlaPolicyRow[] } }) {
    const form = useForm<{ policies: SlaPolicyRow[] }>({ policies: policies.data });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.put(update().url);
    }

    function updateRow(index: number, attributes: Partial<SlaPolicyRow>) {
        const rows = form.data.policies.map((row, i) => (i === index ? { ...row, ...attributes } : row));

        form.setData('policies', rows);
    }

    return (
        <>
            <Head title="SLA Policies" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <FormCard
                    title="SLA Policies"
                    description="Resolution and warning targets per priority, in business minutes."
                    onSubmit={submit}
                    footer={<SubmitButton processing={form.processing}>Save changes</SubmitButton>}
                >
                    {form.data.policies.map((row, index) => (
                        <div
                            key={row.priority}
                            className="grid gap-4 border-b pb-6 last:border-0 last:pb-0 sm:grid-cols-4 sm:items-end"
                        >
                            <p className="text-sm font-medium sm:col-span-4">
                                {TICKET_PRIORITY_LABELS[row.priority]}
                            </p>

                            <TextInput
                                name={`resolution_minutes_${row.priority}`}
                                label="Resolution minutes"
                                type="number"
                                min={1}
                                value={row.resolution_minutes}
                                onChange={(event) =>
                                    updateRow(index, { resolution_minutes: Number(event.target.value) })
                                }
                                error={form.errors[`policies.${index}.resolution_minutes`]}
                                required
                            />

                            <TextInput
                                name={`warning_threshold_${row.priority}`}
                                label="Warning threshold %"
                                type="number"
                                min={1}
                                max={100}
                                value={row.warning_threshold_percent}
                                onChange={(event) =>
                                    updateRow(index, { warning_threshold_percent: Number(event.target.value) })
                                }
                                error={form.errors[`policies.${index}.warning_threshold_percent`]}
                                required
                            />

                            <CheckboxInput
                                name={`is_active_${row.priority}`}
                                label="Active"
                                checked={row.is_active}
                                onChange={(checked) => updateRow(index, { is_active: checked })}
                            />
                        </div>
                    ))}
                </FormCard>
            </div>
        </>
    );
}

SlaPoliciesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'SLA Policies', href: index() },
    ],
};
