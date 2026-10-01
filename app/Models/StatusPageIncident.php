<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A human note on a public status page, with the timeline of how it developed.
 *
 * Monitors answer "is it up?". They cannot answer "do you know, and what are
 * you doing about it?" — and a red dot with no commentary is the least useful
 * version of a status page there is. An incident is the owner saying so, in
 * their own words, to whoever is looking.
 *
 * It is also the only way to report something monitors cannot see: a partial
 * outage, a slow third party, a planned window. Those need saying even when
 * every check is green, which is why an incident is independent of monitor
 * state rather than derived from it.
 */
class StatusPageIncident extends Model
{
    /** The lifecycle, in the order it normally runs. */
    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_IDENTIFIED = 'identified';

    public const STATUS_MONITORING = 'monitoring';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [
        self::STATUS_INVESTIGATING,
        self::STATUS_IDENTIFIED,
        self::STATUS_MONITORING,
        self::STATUS_RESOLVED,
    ];

    /** Open incidents per page. Bounds the public document. */
    public const MAX_OPEN = 10;

    /** How long a resolved incident stays on the public page. */
    public const RESOLVED_VISIBLE_DAYS = 7;

    /** Timeline entries kept per incident. */
    public const MAX_UPDATES = 50;

    protected $fillable = [
        'status_page_id',
        'user_id',
        'title',
        'status',
        'updates',
        'started_at',
        'resolved_at',
    ];

    protected $casts = [
        'updates' => 'array',
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function statusPage()
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * What the public page shows: everything open, plus what was resolved
     * recently — a page that forgets an outage the moment it ends gives a
     * visitor no way to tell "fine now" from "fine all along".
     */
    public function scopePublished($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('resolved_at')
                ->orWhere('resolved_at', '>=', now()->subDays(self::RESOLVED_VISIBLE_DAYS));
        })->orderByDesc('started_at');
    }

    /**
     * Append a timeline entry and move the incident's current status with it.
     *
     * Appending rather than overwriting is the point: the history of what was
     * believed when is most of what makes a status page trustworthy after the
     * fact.
     */
    public function addUpdate(string $status, string $body): void
    {
        $updates = is_array($this->updates) ? $this->updates : [];
        $updates[] = ['at' => now()->toIso8601String(), 'status' => $status, 'body' => $body];

        $this->update([
            'updates' => array_slice($updates, -self::MAX_UPDATES),
            'status' => $status,
            'resolved_at' => $status === self::STATUS_RESOLVED
                ? ($this->resolved_at ?? now())
                // Reopening is allowed: an incident called resolved too early
                // should not need a second incident to correct.
                : null,
        ]);
    }

    /** The owner's view, including who opened it. */
    public function toClientArray(): array
    {
        return $this->toPublicArray() + [
            'id' => $this->id,
            'opened_by' => $this->relationLoaded('user') && $this->user ? $this->user->name : null,
        ];
    }

    /** The public view. No owner identity, no internal ids. */
    public function toPublicArray(): array
    {
        return [
            'title' => $this->title,
            'status' => $this->status,
            'resolved' => $this->isResolved(),
            'started_at' => $this->started_at,
            'resolved_at' => $this->resolved_at,
            'updates' => collect($this->updates ?? [])->map(fn ($u) => [
                'at' => $u['at'] ?? null,
                'status' => $u['status'] ?? null,
                'body' => $u['body'] ?? '',
            ])->values()->all(),
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
