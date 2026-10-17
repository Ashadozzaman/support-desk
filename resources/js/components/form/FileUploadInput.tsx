import type { ChangeEvent } from 'react';
import { FormField } from '@/components/form/FormField';
import { Input } from '@/components/ui/input';

export function FileUploadInput({
    name,
    label,
    error,
    accept,
    multiple = true,
    onChange,
}: {
    name: string;
    label: string;
    error?: string;
    accept?: string;
    multiple?: boolean;
    onChange: (files: File[]) => void;
}) {
    function handleChange(event: ChangeEvent<HTMLInputElement>) {
        onChange(event.target.files ? Array.from(event.target.files) : []);
    }

    return (
        <FormField htmlFor={name} label={label} error={error}>
            <Input
                id={name}
                name={name}
                type="file"
                accept={accept}
                multiple={multiple}
                onChange={handleChange}
            />
        </FormField>
    );
}
