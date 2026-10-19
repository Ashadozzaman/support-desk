<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketEmailThreadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
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
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $toCustomer = $notifiable instanceof User && $notifiable->id === $this->ticket->created_by;

        $mail = (new MailMessage)
            ->subject("New reply on {$this->ticket->ticket_number}")
            ->line("{$this->reply->user->name} replied to \"{$this->ticket->subject}\":")
            // The customer gets the whole answer: someone who wrote in by email may have no
            // password to read it in the app. Staff get a preview.
            ->line($toCustomer ? $this->reply->body : Str::limit($this->reply->body, 200))
            ->action('View ticket', route('tickets.show', $this->ticket->ticket_number));

        return app(TicketEmailThreadService::class)->thread($mail, $this->ticket, $notifiable);
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

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
