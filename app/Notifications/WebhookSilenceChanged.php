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
        return (new MailMessage)
            ->subject(sprintf(
                '[Spi] %s %s',
                $this->endpoint->name,
                $this->recovered ? 'is reporting in again' : 'has gone silent'
            ))
            ->markdown('emails.webhook-silence', [
                'endpointName' => $this->endpoint->name,
                'recovered' => $this->recovered,
                'intervalMinutes' => (int) $this->endpoint->expect_interval_minutes,
                'lastReceived' => $this->endpoint->last_received_at?->toDayDateTimeString() ?? 'it was created',
                'webhooksUrl' => url('/webhooks'),
            ]);
    }
}
