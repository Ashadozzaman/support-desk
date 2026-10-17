import { SelectInput } from '@/components/form/SelectInput';
import type { CategoryOption } from '@/types/ticket';

const UNASSIGNED = '__none__';

export function CategorySelect({
    name = 'category_id',
    label = 'Category',
    error,
    value,
    categories,
    onChange,
}: {
    name?: string;
    label?: string;
    error?: string;
    value: number | null;
    categories: CategoryOption[];
    onChange: (value: number | null) => void;
}) {
    const options = [
        { value: UNASSIGNED, label: 'No category' },
        ...categories.map((category) => ({
            value: String(category.id),
            label: category.name,
        })),
    ];

    return (
        <SelectInput
            name={name}
            label={label}
            error={error}
            value={value === null ? UNASSIGNED : String(value)}
            options={options}
            onChange={(next) => onChange(next === UNASSIGNED ? null : Number(next))}
        />
    );
}
