<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A collection run on a schedule, with alerting on status transitions.
 *
 * The status is a three-state: "unknown" until the first run, then "passing"
 * or "failing". Alerts fire on the transition between those two, not on every
 * failing run — alerting every tick is what makes people mute monitoring.
 */
class Monitor extends Model
{
    public const MAX_PER_USER = 20;

    /** Retained history points per monitor; older results are trimmed. */
    public const RETENTION = 500;

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_PASSING = 'passing';

    public const STATUS_FAILING = 'failing';

    /** Allowed intervals, in minutes. Bounded so a monitor cannot hammer a target. */
    public const INTERVALS = [5, 15, 30, 60, 180, 360, 720, 1440];

    protected $fillable = [
        'user_id',
        'collection_id',
        'environment_id',
        'name',
        'interval_minutes',
        'is_enabled',
        'alerts_enabled',
        'last_status',
        'last_run_at',
        'consecutive_failures',
    ];

    /**
     * In-memory defaults matching the column defaults. Without these, a freshly
     * created model has null for these attributes until it is reloaded, so a
     * new monitor would look disabled (never due, never alerting) for the rest
     * of the request that created it.
     */
    protected $attributes = [
        'interval_minutes' => 60,
        'is_enabled' => true,
        'alerts_enabled' => true,
        'last_status' => self::STATUS_UNKNOWN,
        'consecutive_failures' => 0,
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'alerts_enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function collection()
    {
        return $this->belongsTo(Collection::class);
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function results()
    {
        return $this->hasMany(MonitorResult::class)->latest('id');
    }

    /**
     * Enabled monitors, oldest run first.
     *
     * Dueness is decided in PHP by isDue() rather than in SQL: the interval
     * lives in a column, and the date arithmetic to compare against it is
     * dialect-specific (tests run on SQLite, production on MySQL). The set of
     * enabled monitors is small, so filtering in PHP costs nothing and cannot
     * silently behave differently across drivers.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true)->orderByRaw('last_run_at is null desc')->oldest('last_run_at');
    }

    public function isDue(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        return $this->last_run_at === null
            || $this->last_run_at->addMinutes($this->interval_minutes)->isPast();
    }

    /**
     * Uptime across retained history, as a percentage.
     */
    public function uptime(): ?float
    {
        $total = $this->results()->count();

        if ($total === 0) {
            return null;
        }

        return round($this->results()->where('passed', true)->count() / $total * 100, 1);
    }
}
