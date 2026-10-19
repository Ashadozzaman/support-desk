import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export type CheckboxGroupOption<T extends string | number> = {
    value: T;
    label: string;
};

/** Pick any number of options, e.g. an agent's category skills or a role's permissions. */
export function CheckboxGroupInput<T extends string | number>({
    name,
    label,
    description,
    options,
    value,
    onChange,
    error,
    disabled = false,
}: {
    name: string;
    label: string;
    description?: string;
    options: CheckboxGroupOption<T>[];
    value: T[];
    onChange: (value: T[]) => void;
    error?: string;
    disabled?: boolean;
}) {
    function toggle(option: T, checked: boolean) {
        onChange(checked ? [...value, option] : value.filter((selected) => selected !== option));
    }

    return (
        <fieldset className="grid gap-2">
            <legend className="mb-1 w-full border-b pb-2 text-sm font-medium">{label}</legend>
            {description && <p className="text-muted-foreground text-sm">{description}</p>}
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((option) => {
                    const id = `${name}-${option.value}`;

                    return (
                        <div key={option.value} className="flex items-center gap-2">
                            <Checkbox
                                id={id}
                                checked={value.includes(option.value)}
                                disabled={disabled}
                                onCheckedChange={(checked) => toggle(option.value, checked === true)}
                            />
                            <Label htmlFor={id} className="font-normal">
                                {option.label}
                            </Label>
                        </div>
                    );
                })}
            </div>
            <InputError message={error} />
        </fieldset>
    );
}
