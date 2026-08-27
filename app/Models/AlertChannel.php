<?php

namespace App\Models;

use App\Models\Concerns\SharedInWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A destination for monitor alerts: a Slack or Discord incoming webhook, or a
 * generic endpoint that receives the raw JSON payload.
 *
 * Channels belong to a user and are shared across their monitors, so a Slack
 * URL is entered once rather than per monitor.
 */
class AlertChannel extends Model
{
    use SharedInWorkspace;

    public const MAX_PER_USER = 10;

    public const TYPES = ['slack', 'discord', 'webhook'];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'url',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_delivered_at' => 'datetime',
    ];

    /**
     * Column defaults in memory too, so a freshly created channel is not
     * read back as disabled before it is reloaded.
     */
    protected $attributes = [
        'type' => 'webhook',
        'is_enabled' => true,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function monitors()
    {
        return $this->belongsToMany(Monitor::class);
    }

    /**
     * The URL is a credential: anyone holding a Slack incoming-webhook URL can
     * post to that channel, so it is never returned to the browser in full.
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'url_preview' => $this->urlPreview(),
            'is_enabled' => $this->is_enabled,
            'last_delivered_at' => $this->last_delivered_at,
            'last_error' => $this->last_error,
        ];
    }

    private function urlPreview(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST) ?: '';
        $tail = substr($this->url, -4);

        return $host === '' ? '••••' : $host.'/…'.$tail;
    }
}
