import type { ComponentProps, ReactNode } from 'react';
import { FormField } from '@/components/form/FormField';
import PasswordInput from '@/components/password-input';

type PasswordFieldProps = Omit<ComponentProps<typeof PasswordInput>, 'id'> & {
    name: string;
    label: string;
    error?: string;
    // Rendered beside the label, e.g. a "Forgot password?" link.
    labelAction?: ReactNode;
};

export function PasswordField({ name, label, error, labelAction, ...props }: PasswordFieldProps) {
    return (
        <div className="relative">
            {labelAction && <div className="absolute top-0 right-0 text-sm">{labelAction}</div>}
            <FormField htmlFor={name} label={label} error={error}>
                <PasswordInput id={name} name={name} aria-invalid={!!error} {...props} />
            </FormField>
        </div>
    );
}
