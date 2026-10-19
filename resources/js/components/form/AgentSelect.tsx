import { SelectInput } from '@/components/form/SelectInput';
import type { SelectOptionBadge } from '@/components/form/SelectInput';
import type { AgentOption, AssignableAgent } from '@/types/ticket';

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
    agents: (AgentOption | AssignableAgent)[];
    onChange: (value: number) => void;
}) {
    const options = agents.map((agent) => ({
        value: String(agent.id),
        label: agent.name,
        badges: workloadBadges(agent),
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

// Load as small badges beside the name ("4/10 active", "full", "unavailable");
// the label stays the bare name so the combobox search matches on it.
function workloadBadges(agent: AgentOption | AssignableAgent): SelectOptionBadge[] | undefined {
    if (!('active_tickets_count' in agent)) {
        return undefined;
    }

    const badges: SelectOptionBadge[] = [
        { text: `${agent.active_tickets_count}/${agent.max_active_tickets} active`, variant: 'secondary' },
    ];

    if (!agent.is_available) {
        badges.push({ text: 'unavailable', variant: 'outline' });
    } else if (agent.active_tickets_count >= agent.max_active_tickets) {
        badges.push({ text: 'full', variant: 'destructive' });
    }

    return badges;
}
