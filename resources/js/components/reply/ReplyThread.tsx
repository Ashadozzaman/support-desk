import { ReplyCard } from '@/components/cards/ReplyCard';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ReplyItem } from '@/types/reply';

export function ReplyThread({ replies }: { replies: ReplyItem[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Conversation</CardTitle>
            </CardHeader>
            <CardContent>
                {replies.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No replies yet.</p>
                ) : (
                    <div className="space-y-4">
                        {replies.map((reply) => (
                            <ReplyCard key={reply.id} reply={reply} />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
