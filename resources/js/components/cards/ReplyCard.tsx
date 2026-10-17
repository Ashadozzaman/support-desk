import { AttachmentCard } from '@/components/cards/AttachmentCard';
import { Badge } from '@/components/ui/badge';
import type { ReplyItem } from '@/types/reply';

export function ReplyCard({ reply }: { reply: ReplyItem }) {
    const isNote = reply.type === 'internal_note';

    return (
        <div
            className={`rounded-lg border p-4 ${
                isNote ? 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30' : ''
            }`}
        >
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium">{reply.user?.name ?? 'Unknown'}</p>
                <div className="flex items-center gap-2">
                    {isNote && <Badge variant="outline">Internal note</Badge>}
                    <p className="text-muted-foreground text-xs">
                        {new Date(reply.created_at).toLocaleString()}
                    </p>
                </div>
            </div>

            <p className="mt-2 whitespace-pre-wrap text-sm">{reply.body}</p>

            {reply.attachments.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {reply.attachments.map((attachment) => (
                        <AttachmentCard key={attachment.id} attachment={attachment} />
                    ))}
                </div>
            )}
        </div>
    );
}
