import { SelectInput } from '@/components/form/SelectInput';
import { TICKET_STATUS_LABELS, type TicketStatusValue } from '@/types/ticket';

const options = Object.entries(TICKET_STATUS_LABELS).map(([value, label]) => ({
    value,
    label,
}));

export function StatusSelect({
    name = 'status',
    label = 'Status',
    error,
    value,
    disabled,
    onChange,
}: {
    name?: string;
    label?: string;
    error?: string;
    value: TicketStatusValue | undefined;
    disabled?: boolean;
    onChange: (value: TicketStatusValue) => void;
}) {
    return (
        <SelectInput
            name={name}
            label={label}
            error={error}
            value={value}
            disabled={disabled}
            placeholder="Select a status"
            options={options}
            onChange={(next) => onChange(next as TicketStatusValue)}
        />
    );
}
