<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\MonitorResult;
use App\Models\User;
use App\Notifications\MonitorStatusChanged;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

class MonitorStatusEmailTest extends TestCase
{
    private function notify(string $status): MonitorStatusChanged
    {
        $monitor = new Monitor(['name' => 'Prod API', 'consecutive_failures' => 3]);
        $result = new MonitorResult(['passed_count' => 1, 'total' => 3, 'time_ms' => 120, 'summary' => 'Health check returned 500']);

        return new MonitorStatusChanged($monitor, $result, $status);
    }

    public function test_failing_subject_and_body(): void
    {
        $mail = $this->notify(Monitor::STATUS_FAILING)->toMail(new User);
        $this->assertSame('[Spi] Prod API is failing', $mail->subject);

        $html = (string) app(Markdown::class)->render($mail->markdown, $mail->viewData);
        $this->assertStringContainsString('Prod API is failing', $html);
        $this->assertStringContainsString('Health check returned 500', $html);
        $this->assertStringContainsString('3 runs in a row', $html);
        $this->assertStringContainsString('View monitors', $html);
    }

    public function test_recovered_subject_and_body(): void
    {
        $mail = $this->notify(Monitor::STATUS_PASSING)->toMail(new User);
        $this->assertSame('[Spi] Prod API recovered', $mail->subject);

        $html = (string) app(Markdown::class)->render($mail->markdown, $mail->viewData);
        $this->assertStringContainsString('Prod API has recovered', $html);
        $this->assertStringContainsString('passing its checks again', $html);
        // The failure summary is not shown on a recovery.
        $this->assertStringNotContainsString('returned 500', $html);
    }
}
