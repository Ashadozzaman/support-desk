import type { UserSummary } from '@/types/ticket';

export type ReplyTypeValue = 'reply' | 'internal_note';

export type AttachmentItem = {
    id: number;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    download_url: string;
};

export type ReplyItem = {
    id: number;
    type: ReplyTypeValue;
    body: string;
    user: UserSummary | null;
    attachments: AttachmentItem[];
    created_at: string;
};

export type ReplyAbilities = {
    reply: boolean;
    note: boolean;
};
