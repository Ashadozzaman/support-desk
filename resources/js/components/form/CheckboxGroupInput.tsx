import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export type CheckboxGroupOption = {
    value: number;
    label: string;
};

/** Pick any number of options, e.g. an agent's category skills. */
export function CheckboxGroupInput({
    name,
    label,
    description,
    options,
    value,
    onChange,
    error,
}: {
    name: string;
    label: string;
    description?: string;
    options: CheckboxGroupOption[];
    value: number[];
    onChange: (value: number[]) => void;
    error?: string;
}) {
    function toggle(option: number, checked: boolean) {
        onChange(checked ? [...value, option] : value.filter((selected) => selected !== option));
    }

    return (
        <fieldset className="grid gap-2">
            <legend className="text-sm font-medium">{label}</legend>
            {description && <p className="text-muted-foreground text-sm">{description}</p>}
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((option) => {
                    const id = `${name}-${option.value}`;

                    return (
                        <div key={option.value} className="flex items-center gap-2">
                            <Checkbox
                                id={id}
                                checked={value.includes(option.value)}
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
