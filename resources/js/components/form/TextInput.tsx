import type { InputHTMLAttributes } from 'react';
import { FormField } from '@/components/form/FormField';
import { Input } from '@/components/ui/input';

type TextInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> & {
    name: string;
    label: string;
    error?: string;
};

export function TextInput({ name, label, error, className, ...props }: TextInputProps) {
    return (
        <FormField htmlFor={name} label={label} error={error}>
            <Input id={name} name={name} aria-invalid={!!error} className={className} {...props} />
        </FormField>
    );
}
