<?php

namespace App\Models;

use App\Models\Concerns\SharedInWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An inbound capture URL: whatever arrives at /hook/{token} is recorded and
 * shown to its owner. With an expectation set, the endpoint is a dead-man's
 * switch — the monitor that watches for silence instead of failure. Outbound
 * monitoring asks "does the API answer?"; this answers "did the cron fire,
 * did the agent report in, did the provider actually send the callback?"
 */
class WebhookEndpoint extends Model
{
    use SharedInWorkspace;

    public const MAX_PER_USER = 10;

    /** Captures kept per endpoint; older ones are trimmed. */
    public const RETENTION = 100;

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_RECEIVING = 'receiving';

    public const STATUS_SILENT = 'silent';

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'expect_interval_minutes',
        'alerts_enabled',
        'last_received_at',
        'last_status',
    ];

    protected $casts = [
        'alerts_enabled' => 'boolean',
        'last_received_at' => 'datetime',
    ];

    protected $attributes = [
        'alerts_enabled' => true,
        'last_status' => self::STATUS_UNKNOWN,
    ];

    public static function generateToken(): string
    {
        return Str::lower(Str::random(40));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function captures()
    {
        return $this->hasMany(WebhookCapture::class)->latest('id');
    }

    /**
     * Overdue when an expectation is set and the last hit (or, before any hit,
     * the endpoint's creation) is older than the expected interval.
     */
    public function isOverdue(): bool
    {
        if (! $this->expect_interval_minutes) {
            return false;
        }

        $reference = $this->last_received_at ?? $this->created_at;

        return $reference->addMinutes($this->expect_interval_minutes)->isPast();
    }
}
