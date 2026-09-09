<?php

namespace App\Console\Commands;

use App\Http\Controllers\WebhookCaptureController;
use App\Models\WebhookEndpoint;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Console\Command;

class CheckWebhookSilence extends Command
{
    protected $signature = 'webhooks:check';

    protected $description = 'Mark expectant webhook endpoints silent when nothing has arrived in time';

    public function handle(WebhookCaptureController $controller, AlertDispatcher $dispatcher): int
    {
        $flagged = 0;

        $candidates = WebhookEndpoint::whereNotNull('expect_interval_minutes')
            ->where('last_status', '!=', WebhookEndpoint::STATUS_SILENT)
            ->get();

        foreach ($candidates as $endpoint) {
            if (! $endpoint->isOverdue()) {
                continue;
            }

            $endpoint->forceFill(['last_status' => WebhookEndpoint::STATUS_SILENT])->save();
            $flagged++;

            $this->line($endpoint->name.': SILENT');

            // In-app notification fires regardless of external alert channels.
            \App\Models\UserNotification::recordForWorkspace(
                $endpoint->user,
                'webhook_silent',
                "Webhook silent: {$endpoint->name}",
                'No delivery within the expected window.',
                '/webhooks',
            );

            if ($endpoint->alerts_enabled) {
                $controller->alert($dispatcher, $endpoint, recovered: false);
            }
        }

        $this->info($flagged === 0 ? 'Nothing overdue.' : "Flagged {$flagged} endpoint(s) silent.");

        return self::SUCCESS;
    }
}
