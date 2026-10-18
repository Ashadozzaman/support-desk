import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export function CheckboxInput({
    name,
    label,
    checked,
    onChange,
    error,
}: {
    name: string;
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={name}
                    checked={checked}
                    onCheckedChange={(value) => onChange(value === true)}
                />
                <Label htmlFor={name}>{label}</Label>
            </div>
            <InputError message={error} />
        </div>
    );
}
