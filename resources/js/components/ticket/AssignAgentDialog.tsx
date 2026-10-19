import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { AgentSelect } from '@/components/form/AgentSelect';
import { SubmitButton } from '@/components/form/SubmitButton';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { assign } from '@/routes/tickets';
import type { AssignableAgent } from '@/types/ticket';

export function AssignAgentDialog({
    ticketNumber,
    agents,
    reassign = false,
}: {
    ticketNumber: string;
    agents: AssignableAgent[];
    reassign?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ agent_id: number | null }>({ agent_id: null });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(assign(ticketNumber).url, {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant={reassign ? 'outline' : 'default'}>
                    {reassign ? 'Reassign' : 'Assign'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>{reassign ? 'Reassign ticket' : 'Assign ticket'}</DialogTitle>
                        <DialogDescription>
                            Choose an agent to hand this ticket to. Each shows their active tickets against their
                            capacity.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="py-4">
                        <AgentSelect
                            value={form.data.agent_id}
                            agents={agents}
                            error={form.errors.agent_id}
                            onChange={(value) => form.setData('agent_id', value)}
                        />
                    </div>
                    <DialogFooter>
                        <SubmitButton processing={form.processing} disabled={form.data.agent_id === null}>
                            {reassign ? 'Reassign' : 'Assign'}
                        </SubmitButton>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
