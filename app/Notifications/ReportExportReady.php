<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    /**
     * $url is the signed, expiring download link — generated once by
     * ReportExportService so every channel shows the same one.
     */
    public function __construct(public readonly ReportExport $export, public readonly string $url)
    {
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
            ->subject('Your ticket export is ready')
            ->line("{$this->export->row_count} tickets are in the file.")
            ->action('Download CSV', $this->url)
            ->line("The link works until {$this->export->expires_at?->toDayDateTimeString()} (UTC).");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'export_id' => $this->export->id,
            'message' => "Ticket export ready ({$this->export->row_count} rows)",
            'url' => $this->url,
            // A file, not a page: the UI must open it with a normal browser
            // navigation — an Inertia visit expects an Inertia response.
            'download' => true,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
