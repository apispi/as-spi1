<?php

namespace App\Http\Controllers;

use App\Models\WebhookCapture;
use App\Models\WebhookEndpoint;
use App\Notifications\WebhookSilenceChanged;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The public capture endpoint: ANY method on /hook/{token}.
 *
 * Unauthenticated by design — the token IS the credential, exactly like a
 * Slack incoming-webhook URL. Always answers quickly and never reveals
 * whether a token exists beyond the status code.
 */
class WebhookCaptureController extends Controller
{
    /** Stored body cap; larger payloads are truncated, not rejected. */
    public const MAX_BODY = 65536;

    /** Headers that would store someone else's credentials verbatim. */
    private const REDACTED_HEADERS = ['authorization', 'cookie', 'x-api-key', 'proxy-authorization'];

    public function capture(Request $request, AlertDispatcher $dispatcher, string $token)
    {
        $endpoint = WebhookEndpoint::where('token', $token)->first();

        if (! $endpoint) {
            return response()->json(['ok' => false], 404);
        }

        $body = (string) $request->getContent();
        $truncated = strlen($body) > self::MAX_BODY;

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = in_array(strtolower($name), self::REDACTED_HEADERS, true)
                ? '••••••'
                : implode(', ', $values);
        }

        $capture = $endpoint->captures()->create([
            'method' => $request->method(),
            'headers' => $headers,
            'query' => $request->query() ?: null,
            'body' => $truncated ? substr($body, 0, self::MAX_BODY) : ($body ?: null),
            'ip' => $request->ip(),
        ]);

        $wasSilent = $endpoint->last_status === WebhookEndpoint::STATUS_SILENT;

        $endpoint->forceFill([
            'last_received_at' => now(),
            'last_status' => WebhookEndpoint::STATUS_RECEIVING,
        ])->save();

        // A hit on a silent endpoint is the recovery transition.
        if ($wasSilent && $endpoint->alerts_enabled) {
            $this->alert($dispatcher, $endpoint, recovered: true);
        }

        // Event-driven testing: a configured trigger fires a collection run
        // off the request path, so this responds to the provider immediately.
        if ($endpoint->trigger_collection_id) {
            $this->fireTrigger($endpoint, $body);
        }

        $this->trim($endpoint);

        return response()->json(['ok' => true, 'id' => $capture->id, 'truncated' => $truncated]);
    }

    /**
     * Queue the triggered collection run. Top-level scalar fields of a JSON
     * payload become `webhook_<key>` variables, so the suite can target the
     * exact record the callback named.
     */
    private function fireTrigger(WebhookEndpoint $endpoint, string $body): void
    {
        $variables = ['webhook_method' => request()->method()];

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_scalar($value) && is_string($key)) {
                    $variables['webhook_'.$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                }
            }
        }

        \App\Jobs\RunTriggeredCollection::dispatch(
            $endpoint->user_id,
            $endpoint->trigger_collection_id,
            $endpoint->trigger_environment_id,
            $variables,
            $endpoint->name,
        );
    }

    /**
     * Notify the owner that an endpoint went silent or recovered — email plus
     * every enabled alert channel they have. Failures are logged, never thrown:
     * alerting must not break capture.
     */
    public function alert(AlertDispatcher $dispatcher, WebhookEndpoint $endpoint, bool $recovered): void
    {
        try {
            $endpoint->user->notify(new WebhookSilenceChanged($endpoint, $recovered));
        } catch (Throwable $e) {
            Log::warning('Webhook silence mail failed', ['endpoint' => $endpoint->id, 'error' => $e->getMessage()]);
        }

        $headline = $recovered
            ? sprintf('✅ %s is reporting in again', $endpoint->name)
            : sprintf('🔕 %s has gone silent', $endpoint->name);

        $detail = $recovered
            ? 'A request arrived after the endpoint had been overdue.'
            : sprintf(
                'Expected a request at least every %d minutes; last one %s.',
                $endpoint->expect_interval_minutes,
                $endpoint->last_received_at ? 'at '.$endpoint->last_received_at->toDateTimeString() : 'never arrived'
            );

        foreach ($endpoint->user->alertChannels()->where('is_enabled', true)->get() as $channel) {
            $dispatcher->send($channel, match ($channel->type) {
                'slack' => ['text' => $headline."\n".$detail],
                'discord' => ['content' => $headline."\n".$detail],
                default => [
                    'event' => 'webhook.silence_changed',
                    'status' => $recovered ? 'receiving' : 'silent',
                    'endpoint' => ['id' => $endpoint->id, 'name' => $endpoint->name],
                    'expect_interval_minutes' => $endpoint->expect_interval_minutes,
                    'last_received_at' => $endpoint->last_received_at?->toIso8601String(),
                    'occurred_at' => now()->toIso8601String(),
                ],
            });
        }
    }

    private function trim(WebhookEndpoint $endpoint): void
    {
        $cutoff = $endpoint->captures()->skip(WebhookEndpoint::RETENTION)->value('id');

        if ($cutoff !== null) {
            WebhookCapture::where('webhook_endpoint_id', $endpoint->id)
                ->where('id', '<=', $cutoff)->delete();
        }
    }
}
