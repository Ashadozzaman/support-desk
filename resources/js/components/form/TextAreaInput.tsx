import type { TextareaHTMLAttributes } from 'react';
import { FormField } from '@/components/form/FormField';
import { Textarea } from '@/components/ui/textarea';

type TextAreaInputProps = Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> & {
    name: string;
    label: string;
    error?: string;
};

export function TextAreaInput({ name, label, error, className, ...props }: TextAreaInputProps) {
    return (
        <FormField htmlFor={name} label={label} error={error}>
            <Textarea id={name} name={name} aria-invalid={!!error} className={className} {...props} />
        </FormField>
    );
}
