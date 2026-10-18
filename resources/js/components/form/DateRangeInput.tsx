import { FormField } from '@/components/form/FormField';
import { Input } from '@/components/ui/input';

export function DateRangeInput({
    fromName = 'created_from',
    toName = 'created_to',
    label = 'Created between',
    from,
    to,
    errors,
    onChange,
}: {
    fromName?: string;
    toName?: string;
    label?: string;
    from: string | null;
    to: string | null;
    errors?: { from?: string; to?: string };
    onChange: (range: { from: string | null; to: string | null }) => void;
}) {
    return (
        <FormField htmlFor={fromName} label={label} error={errors?.from ?? errors?.to}>
            <div className="flex items-center gap-2">
                <Input
                    id={fromName}
                    type="date"
                    value={from ?? ''}
                    max={to ?? undefined}
                    onChange={(event) => onChange({ from: event.target.value || null, to })}
                />
                <span className="text-muted-foreground text-sm">to</span>
                <Input
                    id={toName}
                    type="date"
                    value={to ?? ''}
                    min={from ?? undefined}
                    onChange={(event) => onChange({ from, to: event.target.value || null })}
                />
            </div>
        </FormField>
    );
}
