import { Link } from '@inertiajs/react';
import { BotIcon, TicketPlusIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { show as chat } from '@/routes/help/chat';
import { create as createTicket } from '@/routes/tickets';

/**
 * Entry point on the help centre. With the AI assistant off (or no
 * `ai.chat` permission) it offers the ticket form instead.
 */
export function AskAssistantCard({ chatAvailable }: { chatAvailable: boolean }) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-start gap-3">
                    {chatAvailable ? (
                        <BotIcon className="text-muted-foreground mt-0.5 size-6 shrink-0" />
                    ) : (
                        <TicketPlusIcon className="text-muted-foreground mt-0.5 size-6 shrink-0" />
                    )}
                    <div>
                        <p className="font-medium">{chatAvailable ? 'Ask the AI assistant' : 'Need more help?'}</p>
                        <p className="text-muted-foreground text-sm">
                            {chatAvailable
                                ? 'Get an answer from these articles in seconds, or hand over to a human agent.'
                                : 'Create a ticket and an agent will get back to you.'}
                        </p>
                    </div>
                </div>
                <Button asChild>
                    {chatAvailable ? <Link href={chat()}>Start chat</Link> : <Link href={createTicket()}>Create a ticket</Link>}
                </Button>
            </CardContent>
        </Card>
    );
}
