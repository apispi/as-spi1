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

        $mail = (new MailMessage)
            ->subject(sprintf(
                '[Spi] %s %s',
                $this->monitor->name,
                $recovered ? 'recovered' : 'is failing'
            ))
            ->greeting($recovered ? 'Back to normal' : 'Monitor failing');

        if ($recovered) {
            $mail->line(sprintf('"%s" is passing again.', $this->monitor->name));
        } else {
            $mail->line(sprintf('"%s" failed its checks.', $this->monitor->name))
                ->line($this->result->summary ?: 'One or more steps failed.');
        }

        return $mail
            ->line(sprintf(
                '%d of %d steps passed in %d ms.',
                $this->result->passed_count,
                $this->result->total,
                $this->result->time_ms
            ))
            ->action('View monitors', url('/monitors'))
            ->line('You can turn alerts off for this monitor in Spi.');
    }
}
