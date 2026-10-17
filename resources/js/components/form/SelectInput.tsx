import { FormField } from '@/components/form/FormField';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type SelectOption = {
    value: string;
    label: string;
};

export function SelectInput({
    name,
    label,
    error,
    value,
    placeholder,
    options,
    disabled,
    onChange,
}: {
    name: string;
    label: string;
    error?: string;
    value: string | undefined;
    placeholder?: string;
    options: SelectOption[];
    disabled?: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <FormField htmlFor={name} label={label} error={error}>
            <Select value={value} onValueChange={onChange} disabled={disabled}>
                <SelectTrigger id={name} aria-invalid={!!error} className="w-full">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </FormField>
    );
}
