<?php

namespace App\Notifications;

use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The dead-man's-switch alert: an expected webhook stopped arriving, or
 * started again. Sent on the transition only.
 */
class WebhookSilenceChanged extends Notification
{
    use Queueable;

    public function __construct(
        private readonly WebhookEndpoint $endpoint,
        private readonly bool $recovered,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(sprintf(
                '[Spi] %s %s',
                $this->endpoint->name,
                $this->recovered ? 'is reporting in again' : 'has gone silent'
            ));

        if ($this->recovered) {
            return $mail
                ->greeting('Back to normal')
                ->line(sprintf('"%s" received a request after being overdue.', $this->endpoint->name))
                ->action('View webhooks', url('/webhooks'));
        }

        return $mail
            ->greeting('An expected webhook has gone quiet')
            ->line(sprintf(
                '"%s" expects a request at least every %d minutes, but none has arrived since %s.',
                $this->endpoint->name,
                $this->endpoint->expect_interval_minutes,
                $this->endpoint->last_received_at?->toDayDateTimeString() ?? 'it was created'
            ))
            ->line('Silence usually means the sender stopped running — a dead cron, a stuck queue, a revoked callback.')
            ->action('View webhooks', url('/webhooks'));
    }
}
