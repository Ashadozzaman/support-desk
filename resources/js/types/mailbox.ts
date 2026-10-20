export type MailboxEncryptionValue = 'ssl' | 'tls' | 'none';

export type MailboxSettings = {
    is_enabled: boolean;
    host: string;
    port: number;
    encryption: MailboxEncryptionValue;
    username: string;
    folder: string;
    fetch_interval_minutes: number;
    batch_size: number;
    /** The password itself is never sent to the browser. */
    password_source: 'saved' | 'env' | null;
    last_synced_at: string | null;
};

export type MailboxSettingsForm = Omit<MailboxSettings, 'password_source' | 'last_synced_at'> & {
    password: string;
};

export type MailboxSyncStatus = 'running' | 'completed' | 'baseline' | 'failed';

export type MailboxSync = {
    id: number;
    status: MailboxSyncStatus;
    status_label: string;
    triggered_by: string | null;
    fetched_count: number;
    created_count: number;
    replied_count: number;
    skipped_count: number;
    duplicate_count: number;
    failed_count: number;
    error: string | null;
    started_at: string;
    finished_at: string | null;
};
