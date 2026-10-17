import { PaperclipIcon } from 'lucide-react';
import type { AttachmentItem } from '@/types/reply';

export function AttachmentCard({ attachment }: { attachment: AttachmentItem }) {
    return (
        <a
            href={attachment.download_url}
            className="hover:bg-muted flex max-w-full items-center gap-1.5 rounded-md border px-2 py-1 text-xs"
        >
            <PaperclipIcon className="size-3.5 shrink-0" />
            <span className="truncate">{attachment.original_name}</span>
            <span className="text-muted-foreground shrink-0">
                {formatBytes(attachment.size_bytes)}
            </span>
        </a>
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
