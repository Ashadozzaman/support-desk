<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Csv;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail only: a weekly summary belongs in the inbox, not the bell.
 */
class WeeklyReportDigest extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    /**
     * @param  array{period: array{from: string, to: string}, kpis: list<array{label: string, current: int|float|null, previous: int|float|null}>, topBreachingCategories: list<array{name: string, breach_rate_pct: ?float}>, busiestAgents: list<array{name: ?string, active_now: int}>}  $digest
     */
    public function __construct(public readonly array $digest)
    {
        $this->queue = 'notifications';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        ['from' => $from, 'to' => $to] = $this->digest['period'];

        $mail = (new MailMessage)
            ->subject("Support desk weekly report: {$from} – {$to}")
            ->line("Last 7 days ({$from} – {$to}) compared with the 7 days before:");

        foreach ($this->digest['kpis'] as $kpi) {
            $mail->line("• {$kpi['label']}: {$this->format($kpi['current'])} (was {$this->format($kpi['previous'])})");
        }

        if ($this->digest['topBreachingCategories'] !== []) {
            $mail->line('Highest SLA breach rate: '.implode(', ', array_map(
                fn (array $c) => "{$c['name']} ({$this->format($c['breach_rate_pct'])}%)",
                $this->digest['topBreachingCategories'],
            )));
        }

        if ($this->digest['busiestAgents'] !== []) {
            $mail->line('Most open tickets right now: '.implode(', ', array_map(
                fn (array $a) => ($a['name'] ?? 'Unknown')." ({$a['active_now']})",
                $this->digest['busiestAgents'],
            )));
        }

        return $mail
            ->action('Open reports', route('reports.index'))
            ->attachData($this->csv(), "weekly-report-{$from}_{$to}.csv", ['mime' => 'text/csv']);
    }

    private function csv(): string
    {
        $handle = Csv::open('php://temp', 'w+');
        Csv::writeRow($handle, ['Metric', 'Last 7 days', 'Previous 7 days']);

        foreach ($this->digest['kpis'] as $kpi) {
            Csv::writeRow($handle, [$kpi['label'], $kpi['current'], $kpi['previous']]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function format(int|float|null $value): string
    {
        return $value === null ? '—' : (string) $value;
    }
}
