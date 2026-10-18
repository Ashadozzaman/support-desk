import { Head, useForm } from '@inertiajs/react';
import { DownloadIcon } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { FileUploadInput } from '@/components/form/FileUploadInput';
import { SubmitButton } from '@/components/form/SubmitButton';
import { dashboard } from '@/routes';
import { create, index, sample, store } from '@/routes/admin/imports';
import { TICKET_PRIORITY_LABELS } from '@/types/ticket';

type ImportFormValues = {
    file: File | null;
};

export default function ImportsCreate({ maxFileSizeMb }: { maxFileSizeMb: number }) {
    const form = useForm<ImportFormValues>({ file: null });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(store.url(), { forceFormData: true });
    }

    return (
        <>
            <Head title="New Import" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <FormCard
                    title="Import tickets from CSV"
                    description="Large files are processed in the background — you'll land on a progress page after uploading."
                    onSubmit={submit}
                    footer={<SubmitButton processing={form.processing}>Upload</SubmitButton>}
                >
                    <div className="bg-muted/40 space-y-3 rounded-md border p-4 text-sm">
                        <div>
                            <p className="font-medium">Required columns</p>
                            <p className="text-muted-foreground">
                                <code>subject</code>, <code>description</code>, <code>priority</code>,{' '}
                                <code>customer_email</code>
                            </p>
                        </div>

                        <div>
                            <p className="font-medium">Optional columns</p>
                            <ul className="text-muted-foreground list-disc space-y-1 pl-5">
                                <li>
                                    <code>category</code> — a category slug (e.g. <code>billing</code>). Left
                                    blank, the ticket has no category.
                                </li>
                                <li>
                                    <code>external_ref</code> — an id from your source system, used to skip a
                                    row if it's already been imported. If you leave this column out, rows are
                                    still deduplicated automatically: a row with the exact same subject,
                                    description, priority, category and customer_email as one already imported
                                    is skipped as a duplicate.
                                </li>
                            </ul>
                        </div>

                        <div>
                            <p className="font-medium">Rules</p>
                            <ul className="text-muted-foreground list-disc space-y-1 pl-5">
                                <li>
                                    <code>priority</code> must be one of:{' '}
                                    {Object.values(TICKET_PRIORITY_LABELS).join(', ')} (lowercase in the file,
                                    e.g. <code>high</code>)
                                </li>
                                <li><code>customer_email</code> must match an existing customer account</li>
                                <li><code>category</code>, if set, must match an existing category's slug</li>
                                <li>a row failing any rule is skipped and reported — it never stops the rest of the file</li>
                                <li>max file size: {maxFileSizeMb} MB, .csv or .txt</li>
                            </ul>
                        </div>
                    </div>

                    <FileUploadInput
                        name="file"
                        label="CSV file"
                        accept=".csv,.txt"
                        multiple={false}
                        error={form.errors.file}
                        onChange={(files) => form.setData('file', files[0] ?? null)}
                    />

                    <a
                        href={sample.url()}
                        className="text-primary inline-flex items-center gap-1.5 text-sm hover:underline"
                    >
                        <DownloadIcon className="size-3.5" />
                        Download sample CSV
                    </a>
                </FormCard>
            </div>
        </>
    );
}

ImportsCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'CSV Imports', href: index() },
        { title: 'New import', href: create() },
    ],
};
