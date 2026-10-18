<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ReplyReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
    ) {
        // Queueable::$queue is untyped — redeclaring it as `public string`
        // here causes a PHP fatal (incompatible trait property composition),
        // so it's set at runtime instead.
        $this->queue = 'notifications';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New reply on {$this->ticket->ticket_number}")
            ->line("{$this->reply->user->name} replied to \"{$this->ticket->subject}\":")
            ->line(Str::limit($this->reply->body, 200))
            ->action('View ticket', route('tickets.show', $this->ticket->ticket_number));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'reply_id' => $this->reply->id,
            'message' => "New reply on {$this->ticket->ticket_number} from {$this->reply->user->name}",
            'url' => route('tickets.show', $this->ticket->ticket_number),
        ];
    }
}
