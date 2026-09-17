<?php

namespace App\Notifications;

use App\Models\Monitor;
use App\Models\MonitorResult;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a monitor transitions between passing and failing — never on
 * every failing run.
 */
class MonitorStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Monitor $monitor,
        private readonly MonitorResult $result,
        private readonly string $status,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $recovered = $this->status === Monitor::STATUS_PASSING;

        return (new MailMessage)
            ->subject(sprintf(
                '[Spi] %s %s',
                $this->monitor->name,
                $recovered ? 'recovered' : 'is failing'
            ))
            ->markdown('emails.monitor-status', [
                'monitorName' => $this->monitor->name,
                'recovered' => $recovered,
                'summary' => $recovered ? null : ($this->result->summary ?: 'One or more steps failed.'),
                'passedCount' => (int) $this->result->passed_count,
                'total' => (int) $this->result->total,
                'timeMs' => (int) $this->result->time_ms,
                'consecutiveFailures' => (int) $this->monitor->consecutive_failures,
                'monitorsUrl' => url('/monitors'),
            ]);
    }
}
