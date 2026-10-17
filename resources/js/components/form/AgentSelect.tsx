import { SelectInput } from '@/components/form/SelectInput';
import type { AgentOption } from '@/types/ticket';

export function AgentSelect({
    name = 'agent_id',
    label = 'Agent',
    error,
    value,
    agents,
    onChange,
}: {
    name?: string;
    label?: string;
    error?: string;
    value: number | null;
    agents: AgentOption[];
    onChange: (value: number) => void;
}) {
    const options = agents.map((agent) => ({
        value: String(agent.id),
        label: agent.name,
    }));

    return (
        <SelectInput
            name={name}
            label={label}
            error={error}
            value={value === null ? undefined : String(value)}
            placeholder="Select an agent"
            options={options}
            onChange={(next) => onChange(Number(next))}
        />
    );
}
