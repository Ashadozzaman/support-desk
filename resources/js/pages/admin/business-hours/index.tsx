import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { CheckboxInput } from '@/components/form/CheckboxInput';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextInput } from '@/components/form/TextInput';
import { ConfirmDialog } from '@/components/feedback/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index, update } from '@/routes/admin/business-hours';
import { destroy, store } from '@/routes/admin/holidays';

const DAY_LABELS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

type BusinessHourRow = {
    day_of_week: number;
    is_working_day: boolean;
    start_time: string | null;
    end_time: string | null;
};

type HolidayRow = {
    id: number;
    date: string;
    name: string;
};

export default function BusinessHoursIndex({
    hours,
    holidays,
}: {
    hours: { data: BusinessHourRow[] };
    holidays: { data: HolidayRow[] };
}) {
    const hoursForm = useForm<{ hours: BusinessHourRow[] }>({ hours: hours.data });
    const holidayForm = useForm<{ date: string; name: string }>({ date: '', name: '' });

    function submitHours(event: FormEvent) {
        event.preventDefault();

        hoursForm.put(update().url);
    }

    function submitHoliday(event: FormEvent) {
        event.preventDefault();

        holidayForm.post(store().url, { onSuccess: () => holidayForm.reset() });
    }

    function updateRow(index: number, attributes: Partial<BusinessHourRow>) {
        const rows = hoursForm.data.hours.map((row, i) => (i === index ? { ...row, ...attributes } : row));

        hoursForm.setData('hours', rows);
    }

    function removeHoliday(id: number) {
        router.delete(destroy(id).url, { preserveScroll: true });
    }

    return (
        <>
            <Head title="Business Hours" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <FormCard
                    title="Business Hours"
                    description="Working days and hours used to calculate SLA deadlines."
                    onSubmit={submitHours}
                    footer={<SubmitButton processing={hoursForm.processing}>Save changes</SubmitButton>}
                >
                    {hoursForm.data.hours.map((row, index) => (
                        <div
                            key={row.day_of_week}
                            className="grid gap-4 border-b pb-6 last:border-0 last:pb-0 sm:grid-cols-4 sm:items-end"
                        >
                            <p className="text-sm font-medium sm:col-span-4">{DAY_LABELS[row.day_of_week]}</p>

                            <CheckboxInput
                                name={`is_working_day_${row.day_of_week}`}
                                label="Working day"
                                checked={row.is_working_day}
                                onChange={(checked) => updateRow(index, { is_working_day: checked })}
                            />

                            <TextInput
                                name={`start_time_${row.day_of_week}`}
                                label="Start time"
                                type="time"
                                value={row.start_time ?? ''}
                                disabled={!row.is_working_day}
                                onChange={(event) => updateRow(index, { start_time: event.target.value })}
                                error={hoursForm.errors[`hours.${index}.start_time`]}
                            />

                            <TextInput
                                name={`end_time_${row.day_of_week}`}
                                label="End time"
                                type="time"
                                value={row.end_time ?? ''}
                                disabled={!row.is_working_day}
                                onChange={(event) => updateRow(index, { end_time: event.target.value })}
                                error={hoursForm.errors[`hours.${index}.end_time`]}
                            />
                        </div>
                    ))}
                </FormCard>

                <FormCard
                    title="Holidays"
                    description="Non-working days excluded from SLA calculations."
                    onSubmit={submitHoliday}
                    footer={<SubmitButton processing={holidayForm.processing}>Add holiday</SubmitButton>}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextInput
                            name="date"
                            label="Date"
                            type="date"
                            value={holidayForm.data.date}
                            onChange={(event) => holidayForm.setData('date', event.target.value)}
                            error={holidayForm.errors.date}
                            required
                        />
                        <TextInput
                            name="name"
                            label="Name"
                            value={holidayForm.data.name}
                            onChange={(event) => holidayForm.setData('name', event.target.value)}
                            error={holidayForm.errors.name}
                            required
                        />
                    </div>

                    <div className="space-y-2">
                        {holidays.data.length === 0 && (
                            <p className="text-muted-foreground text-sm">No holidays configured.</p>
                        )}
                        {holidays.data.map((holiday) => (
                            <div
                                key={holiday.id}
                                className="flex items-center justify-between rounded-md border px-3 py-2"
                            >
                                <div>
                                    <p className="text-sm font-medium">{holiday.name}</p>
                                    <p className="text-muted-foreground text-xs">{holiday.date}</p>
                                </div>
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline" size="sm">
                                            Remove
                                        </Button>
                                    }
                                    title="Remove holiday?"
                                    description={`${holiday.name} (${holiday.date}) will no longer be excluded from SLA calculations.`}
                                    confirmLabel="Remove"
                                    destructive
                                    onConfirm={() => removeHoliday(holiday.id)}
                                />
                            </div>
                        ))}
                    </div>
                </FormCard>
            </div>
        </>
    );
}

BusinessHoursIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Business Hours', href: index() },
    ],
};
