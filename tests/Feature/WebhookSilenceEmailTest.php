<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\WebhookSilenceChanged;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

class WebhookSilenceEmailTest extends TestCase
{
    private function endpoint(): WebhookEndpoint
    {
        return new WebhookEndpoint([
            'name' => 'Payments callback',
            'expect_interval_minutes' => 30,
        ]);
    }

    public function test_silent_subject_and_body(): void
    {
        $mail = (new WebhookSilenceChanged($this->endpoint(), false))->toMail(new User);
        $this->assertSame('[Spi] Payments callback has gone silent', $mail->subject);

        $html = (string) app(Markdown::class)->render($mail->markdown, $mail->viewData);
        $this->assertStringContainsString('Payments callback has gone silent', $html);
        $this->assertStringContainsString('every 30', $html);
        $this->assertStringContainsString('dead cron', $html);
        $this->assertStringContainsString('View webhooks', $html);
    }

    public function test_recovered_subject_and_body(): void
    {
        $mail = (new WebhookSilenceChanged($this->endpoint(), true))->toMail(new User);
        $this->assertSame('[Spi] Payments callback is reporting in again', $mail->subject);

        $html = (string) app(Markdown::class)->render($mail->markdown, $mail->viewData);
        $this->assertStringContainsString('reporting in again', $html);
        $this->assertStringContainsString('Delivery has', $html);
        // The "gone silent" explanation is not shown on recovery.
        $this->assertStringNotContainsString('dead cron', $html);
    }
}
