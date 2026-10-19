<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by AlertOnFailedJob. Carries only the job class, queue and exception
 * class — the exception message can contain data the recipient shouldn't
 * get by email; the full (redacted) details are in the `queue` log.
 */
class JobFailedAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    public function __construct(
        public readonly string $jobName,
        public readonly ?string $failedQueue,
        public readonly string $exception,
    ) {
        // Queueable::$queue is the queue *this notification* is sent on —
        // hence `failedQueue` for the queue the job failed on.
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
            ->subject('Background job failed: '.class_basename($this->jobName))
            ->line("{$this->jobName} failed on queue \"{$this->failedQueueLabel()}\" with {$this->exception}.")
            ->line('Repeated failures of the same job are suppressed for a few minutes. Details are in the queue log.')
            ->action('Open system health', route('admin.system-health'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'job' => $this->jobName,
            'queue' => $this->failedQueueLabel(),
            'exception' => $this->exception,
            'message' => 'Background job failed: '.class_basename($this->jobName),
            'url' => route('admin.system-health'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    private function failedQueueLabel(): string
    {
        return $this->failedQueue ?? 'default';
    }
}
