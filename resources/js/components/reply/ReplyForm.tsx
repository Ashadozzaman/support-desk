import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { FileUploadInput } from '@/components/form/FileUploadInput';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextAreaInput } from '@/components/form/TextAreaInput';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { store } from '@/routes/tickets/replies';
import type { ReplyTypeValue } from '@/types/reply';

type ReplyFormValues = {
    body: string;
    type: ReplyTypeValue;
    attachments: File[];
};

export function ReplyForm({ ticketNumber, canNote }: { ticketNumber: string; canNote: boolean }) {
    const form = useForm<ReplyFormValues>({
        body: '',
        type: 'reply',
        attachments: [],
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(store.url({ ticket: ticketNumber }), {
            forceFormData: true,
            onSuccess: () => form.reset(),
        });
    }

    const isNote = form.data.type === 'internal_note';

    return (
        <FormCard
            title={isNote ? 'Add an internal note' : 'Add a reply'}
            description={
                canNote
                    ? 'Internal notes are only visible to agents and admins.'
                    : 'The ticket owner will see this reply.'
            }
            onSubmit={submit}
            footer={
                <SubmitButton processing={form.processing}>
                    {isNote ? 'Add note' : 'Send reply'}
                </SubmitButton>
            }
        >
            {canNote && (
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={form.data.type}
                    onValueChange={(value) => value && form.setData('type', value as ReplyTypeValue)}
                >
                    <ToggleGroupItem value="reply">Reply</ToggleGroupItem>
                    <ToggleGroupItem value="internal_note">Internal note</ToggleGroupItem>
                </ToggleGroup>
            )}

            <TextAreaInput
                name="body"
                label={isNote ? 'Note' : 'Reply'}
                value={form.data.body}
                onChange={(event) => form.setData('body', event.target.value)}
                error={form.errors.body}
                rows={4}
                required
            />

            <FileUploadInput
                name="attachments"
                label="Attachments"
                error={form.errors['attachments.0'] ?? form.errors.attachments}
                onChange={(files) => form.setData('attachments', files)}
            />
        </FormCard>
    );
}
