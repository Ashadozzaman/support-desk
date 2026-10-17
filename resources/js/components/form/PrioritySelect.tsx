import { SelectInput } from '@/components/form/SelectInput';
import { TICKET_PRIORITY_LABELS, type TicketPriorityValue } from '@/types/ticket';

const options = Object.entries(TICKET_PRIORITY_LABELS).map(([value, label]) => ({
    value,
    label,
}));

export function PrioritySelect({
    name = 'priority',
    label = 'Priority',
    error,
    value,
    onChange,
}: {
    name?: string;
    label?: string;
    error?: string;
    value: TicketPriorityValue | undefined;
    onChange: (value: TicketPriorityValue) => void;
}) {
    return (
        <SelectInput
            name={name}
            label={label}
            error={error}
            value={value}
            placeholder="Select a priority"
            options={options}
            onChange={(next) => onChange(next as TicketPriorityValue)}
        />
    );
}
