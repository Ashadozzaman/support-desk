import { Head, router, useForm } from '@inertiajs/react';
import { MailIcon, RefreshCwIcon } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { EmptyState } from '@/components/data/EmptyState';
import { CheckboxInput } from '@/components/form/CheckboxInput';
import { PasswordField } from '@/components/form/PasswordField';
import { SelectInput } from '@/components/form/SelectInput';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextInput } from '@/components/form/TextInput';
import { MailboxSyncTable } from '@/components/mailbox/MailboxSyncTable';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { edit, sync, test, update } from '@/routes/admin/mailbox';
import type { MailboxEncryptionValue, MailboxSettings, MailboxSettingsForm, MailboxSync } from '@/types/mailbox';

const PASSWORD_HINT: Record<'saved' | 'env', string> = {
    saved: 'A password is saved (encrypted). Leave blank to keep it.',
    env: 'Using IMAP_PASSWORD from .env. Leave blank to keep using it, or enter one to save it here.',
};

export default function MailboxIndex({
    settings,
    encryptionOptions,
    syncs,
}: {
    settings: { data: MailboxSettings };
    encryptionOptions: { value: MailboxEncryptionValue; label: string }[];
    syncs: { data: MailboxSync[] };
}) {
    const { password_source: passwordSource, last_synced_at: lastSyncedAt, ...fields } = settings.data;
    const form = useForm<MailboxSettingsForm>({ ...fields, password: '' });

    function save(event: FormEvent) {
        event.preventDefault();

        form.put(update().url, { preserveScroll: true, onSuccess: () => form.setData('password', '') });
    }

    function testConnection() {
        form.post(test().url, { preserveScroll: true });
    }

    function syncNow() {
        router.post(sync().url, {}, { preserveScroll: true });
    }

    return (
        <>
            <Head title="Mailbox" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <FormCard
                    title="Support mailbox"
                    description="Emails to this mailbox become tickets; replies are added to the same ticket. Gmail: use an app password (docs/IMAP.md §7)."
                    onSubmit={save}
                    footer={
                        <>
                            <Button type="button" variant="outline" onClick={testConnection} disabled={form.processing}>
                                Test connection
                            </Button>
                            <SubmitButton processing={form.processing}>Save settings</SubmitButton>
                        </>
                    }
                >
                    <CheckboxInput
                        name="is_enabled"
                        label="Enabled — fetch new mail on the schedule below"
                        checked={form.data.is_enabled}
                        onChange={(checked) => form.setData('is_enabled', checked)}
                        error={form.errors.is_enabled}
                    />

                    <div className="grid gap-4 sm:grid-cols-3">
                        <TextInput
                            name="host"
                            label="Host"
                            value={form.data.host}
                            onChange={(event) => form.setData('host', event.target.value)}
                            error={form.errors.host}
                            required
                        />
                        <TextInput
                            name="port"
                            label="Port"
                            type="number"
                            min={1}
                            max={65535}
                            value={form.data.port}
                            onChange={(event) => form.setData('port', Number(event.target.value))}
                            error={form.errors.port}
                            required
                        />
                        <SelectInput
                            name="encryption"
                            label="Encryption"
                            value={form.data.encryption}
                            options={encryptionOptions}
                            onChange={(value) => form.setData('encryption', value as MailboxEncryptionValue)}
                            error={form.errors.encryption}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextInput
                            name="username"
                            label="Username (email address)"
                            autoComplete="off"
                            value={form.data.username}
                            onChange={(event) => form.setData('username', event.target.value)}
                            error={form.errors.username}
                            required
                        />
                        <div className="space-y-1">
                            <PasswordField
                                name="password"
                                label="App password"
                                autoComplete="new-password"
                                placeholder={passwordSource ? '••••••••••••••••' : ''}
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                error={form.errors.password}
                            />
                            <p className="text-muted-foreground text-xs">
                                {passwordSource ? PASSWORD_HINT[passwordSource] : 'No password set yet.'}
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <TextInput
                            name="folder"
                            label="Folder"
                            value={form.data.folder}
                            onChange={(event) => form.setData('folder', event.target.value)}
                            error={form.errors.folder}
                            required
                        />
                        <TextInput
                            name="fetch_interval_minutes"
                            label="Fetch every (minutes)"
                            type="number"
                            min={1}
                            max={1440}
                            value={form.data.fetch_interval_minutes}
                            onChange={(event) => form.setData('fetch_interval_minutes', Number(event.target.value))}
                            error={form.errors.fetch_interval_minutes}
                            required
                        />
                        <TextInput
                            name="batch_size"
                            label="Messages per sync"
                            type="number"
                            min={1}
                            max={200}
                            value={form.data.batch_size}
                            onChange={(event) => form.setData('batch_size', Number(event.target.value))}
                            error={form.errors.batch_size}
                            required
                        />
                    </div>
                </FormCard>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-semibold">Sync history</h2>
                        <p className="text-muted-foreground text-sm">
                            {lastSyncedAt ? `Last sync ${new Date(lastSyncedAt).toLocaleString()}.` : 'Not synced yet.'}{' '}
                            The first sync only records the starting point — older mail is never imported.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => router.reload({ only: ['syncs', 'settings'] })}>
                            <RefreshCwIcon />
                            Refresh
                        </Button>
                        <Button onClick={syncNow} disabled={!settings.data.is_enabled}>
                            Sync now
                        </Button>
                    </div>
                </div>

                {syncs.data.length === 0 ? (
                    <EmptyState icon={MailIcon} title="No syncs yet" description="Enable the mailbox and save, then sync." />
                ) : (
                    <MailboxSyncTable syncs={syncs.data} />
                )}
            </div>
        </>
    );
}

MailboxIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Mailbox', href: edit() },
    ],
};
