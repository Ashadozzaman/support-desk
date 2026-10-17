import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { CategorySelect } from '@/components/form/CategorySelect';
import { PrioritySelect } from '@/components/form/PrioritySelect';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextAreaInput } from '@/components/form/TextAreaInput';
import { TextInput } from '@/components/form/TextInput';
import { store, update } from '@/routes/tickets';
import type { CategoryOption, Ticket, TicketPriorityValue } from '@/types/ticket';

type TicketFormValues = {
    subject: string;
    description: string;
    priority: TicketPriorityValue;
    category_id: number | null;
};

export function TicketForm({
    mode,
    ticket,
    categories,
}: {
    mode: 'create' | 'edit';
    ticket?: Ticket;
    categories: CategoryOption[];
}) {
    const form = useForm<TicketFormValues>({
        subject: ticket?.subject ?? '',
        description: ticket?.description ?? '',
        priority: ticket?.priority ?? 'medium',
        category_id: ticket?.category?.id ?? null,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (mode === 'create') {
            form.post(store().url);
        } else if (ticket) {
            form.put(update(ticket.ticket_number).url);
        }
    }

    return (
        <FormCard
            title={mode === 'create' ? 'New ticket' : 'Edit ticket'}
            description={
                mode === 'create'
                    ? 'Describe the issue so an agent can help.'
                    : 'Update the ticket details.'
            }
            onSubmit={submit}
            footer={
                <SubmitButton processing={form.processing}>
                    {mode === 'create' ? 'Create ticket' : 'Save changes'}
                </SubmitButton>
            }
        >
            <TextInput
                name="subject"
                label="Subject"
                value={form.data.subject}
                onChange={(event) => form.setData('subject', event.target.value)}
                error={form.errors.subject}
                maxLength={200}
                required
            />

            <TextAreaInput
                name="description"
                label="Description"
                value={form.data.description}
                onChange={(event) => form.setData('description', event.target.value)}
                error={form.errors.description}
                rows={6}
                required
            />

            <PrioritySelect
                value={form.data.priority}
                onChange={(value) => form.setData('priority', value)}
                error={form.errors.priority}
            />

            <CategorySelect
                value={form.data.category_id}
                categories={categories}
                onChange={(value) => form.setData('category_id', value)}
                error={form.errors.category_id}
            />
        </FormCard>
    );
}
