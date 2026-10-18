<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    public function __construct(public readonly Ticket $ticket)
    {
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
        return (new MailMessage)
            ->subject("Ticket {$this->ticket->ticket_number} assigned")
            ->line("Ticket \"{$this->ticket->subject}\" has been assigned to {$this->ticket->assignedAgent?->name}.")
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
            'subject' => $this->ticket->subject,
            'message' => "Ticket {$this->ticket->ticket_number} assigned to {$this->ticket->assignedAgent?->name}",
            'url' => route('tickets.show', $this->ticket->ticket_number),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
