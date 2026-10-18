import { FormField } from '@/components/form/FormField';
import { Input } from '@/components/ui/input';

/** Native month picker; value is `YYYY-MM` (or null when cleared). */
export function MonthInput({
    name,
    label,
    value,
    max,
    error,
    onChange,
}: {
    name: string;
    label: string;
    value: string | null;
    max?: string;
    error?: string;
    onChange: (value: string | null) => void;
}) {
    return (
        <FormField htmlFor={name} label={label} error={error}>
            <Input
                id={name}
                type="month"
                value={value ?? ''}
                max={max}
                aria-invalid={!!error}
                onChange={(event) => onChange(event.target.value || null)}
            />
        </FormField>
    );
}
