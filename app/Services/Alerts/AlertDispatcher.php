<?php

namespace App\Services\Alerts;

use App\Models\AlertChannel;
use App\Models\Monitor;
use App\Models\MonitorResult;
use App\Services\Security\SsrfException;
use App\Services\Security\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers monitor alerts to Slack, Discord, or a generic webhook.
 *
 * These URLs are user-supplied and we POST to them from the server, which is
 * an SSRF surface exactly like the testers: the URL is validated on write and
 * the address is pinned again here, because the host could have been
 * re-pointed since. Delivery never throws — a broken channel records its error
 * and the monitor run stands.
 */
class AlertDispatcher
{
    /**
     * Kept short: alerting is a side effect of a run, not the run itself.
     */
    private const TIMEOUT = 8;

    public function __construct(private readonly ?SsrfGuard $guard = null)
    {
    }

    /**
     * Send a status change to every enabled channel on the monitor.
     *
     * @return int the number of channels delivered to
     */
    public function dispatch(Monitor $monitor, MonitorResult $result, string $status): int
    {
        $delivered = 0;

        foreach ($monitor->alertChannels()->where('is_enabled', true)->get() as $channel) {
            if ($this->send($channel, $this->payload($channel, $monitor, $result, $status))) {
                $delivered++;
            }
        }

        return $delivered;
    }

    /**
     * Deliver an arbitrary payload, recording success or the reason it failed.
     */
    public function send(AlertChannel $channel, array $payload): bool
    {
        try {
            $pinned = ($this->guard ?? new SsrfGuard)->pinnedOptions($channel->url);
        } catch (SsrfException $e) {
            return $this->recordFailure($channel, $e->getMessage());
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false] + $pinned)
                ->timeout(self::TIMEOUT)
                ->asJson()
                ->post($channel->url, $payload);

            if ($response->failed()) {
                return $this->recordFailure($channel, 'Endpoint returned HTTP '.$response->status());
            }
        } catch (Throwable $e) {
            return $this->recordFailure($channel, $e->getMessage());
        }

        $channel->forceFill([
            'last_delivered_at' => now(),
            'last_error' => null,
        ])->save();

        return true;
    }

    /**
     * Build the body for a status change, in the shape the destination wants.
     */
    public function payload(AlertChannel $channel, Monitor $monitor, MonitorResult $result, string $status): array
    {
        $recovered = $status === Monitor::STATUS_PASSING;

        $headline = sprintf(
            '%s %s — %s',
            $recovered ? '✅' : '🔴',
            $monitor->name,
            $recovered ? 'recovered' : 'is failing'
        );

        $detail = sprintf(
            '%d/%d steps passed in %d ms.%s',
            $result->passed_count,
            $result->total,
            $result->time_ms,
            $recovered || ! $result->summary ? '' : ' '.$result->summary
        );

        return match ($channel->type) {
            // Slack and Discord each accept a single text field on an incoming
            // webhook; anything richer needs per-workspace configuration.
            'slack' => ['text' => $headline."\n".$detail],
            'discord' => ['content' => $headline."\n".$detail],
            // A generic endpoint gets the structured event, which is more
            // useful to a script than a sentence.
            default => [
                'event' => 'monitor.status_changed',
                'status' => $status,
                'monitor' => ['id' => $monitor->id, 'name' => $monitor->name],
                'summary' => $result->summary,
                'passed_count' => $result->passed_count,
                'total' => $result->total,
                'time_ms' => $result->time_ms,
                'occurred_at' => now()->toIso8601String(),
            ],
        };
    }

    private function recordFailure(AlertChannel $channel, string $error): bool
    {
        Log::warning('Alert delivery failed', ['channel' => $channel->id, 'error' => $error]);

        $channel->forceFill([
            'last_error' => mb_substr($error, 0, 255),
        ])->save();

        return false;
    }
}
