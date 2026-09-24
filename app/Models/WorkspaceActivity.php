<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Who changed what" across a shared workspace.
 *
 * A workspace lets colleagues edit each other's saved requests, collections
 * and environments, which immediately raises the question this answers: the
 * collection that behaves differently today — did somebody change it, and who?
 *
 * It is a work log, not a security log. Sign-ins, password changes and the
 * rest live in AuditEvent, which is retained differently and shown elsewhere.
 */
class WorkspaceActivity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'workspace_activity';

    /** Rows kept per actor. Bounds the table without a scheduled prune. */
    public const KEEP_PER_USER = 500;

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'subject_name',
        'action',
        'summary',
        'changed',
        'before',
    ];

    protected $casts = [
        'changed' => 'array',
        'before' => 'array',
    ];

    /**
     * The resource types an entry can be restored onto, mapped to their model.
     * Anything not listed simply has no undo — better than guessing at a class
     * name from a string that arrives in a URL.
     */
    public const RESTORABLE = [
        'saved_request' => SavedRequest::class,
        'collection' => Collection::class,
        'environment' => Environment::class,
        'monitor' => Monitor::class,
        'webhook_endpoint' => WebhookEndpoint::class,
        'mcp_mock' => McpMock::class,
        'alert_channel' => AlertChannel::class,
        'status_page' => StatusPage::class,
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function record(
        int $userId,
        string $type,
        int $subjectId,
        ?string $name,
        string $action,
        ?string $summary = null,
        ?array $changed = null,
        ?array $before = null,
    ): self {
        $entry = static::create([
            'user_id' => $userId,
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'subject_name' => $name !== null ? mb_substr($name, 0, 255) : null,
            'action' => $action,
            'summary' => $summary !== null ? mb_substr($summary, 0, 255) : null,
            'changed' => $changed ?: null,
            'before' => $before ?: null,
        ]);

        $keep = static::where('user_id', $userId)->latest('id')->limit(self::KEEP_PER_USER)->pluck('id');
        static::where('user_id', $userId)->whereNotIn('id', $keep)->delete();

        return $entry;
    }

    /** Everything done by anyone in $user's workspace, newest first. */
    public function scopeInWorkspaceOf($query, User $user)
    {
        return $query->whereIn('user_id', $user->workspaceUserIds());
    }

    /** A readable label for the kind of thing that changed. */
    public function subjectLabel(): string
    {
        return [
            'saved_request' => 'request',
            'collection' => 'collection',
            'environment' => 'environment',
            'monitor' => 'monitor',
            'webhook_endpoint' => 'webhook',
            'mcp_mock' => 'mock',
            'alert_channel' => 'alert channel',
            'status_page' => 'status page',
        ][$this->subject_type] ?? str_replace('_', ' ', $this->subject_type);
    }

    /** The model class this entry can be restored onto, if any. */
    public function subjectClass(): ?string
    {
        return self::RESTORABLE[$this->subject_type] ?? null;
    }

    /**
     * Whether this entry can be undone.
     *
     * Only updates: putting back the fields an edit replaced is unambiguous.
     * Re-creating a deleted resource is not — a collection's steps and a
     * monitor's channel links live in their own tables and went with it — so
     * a delete records what was lost without offering a one-click undo that
     * would quietly restore a hollow copy.
     */
    public function isRestorable(): bool
    {
        return $this->action === self::ACTION_UPDATED
            && $this->subjectClass() !== null
            && is_array($this->before)
            && $this->before !== [];
    }

    /**
     * The previous values, with credential-bearing attributes withheld.
     *
     * They are still stored, and still restored server-side; what must not
     * happen is handing a browser a secret it would never otherwise be sent.
     *
     * @return array<string, mixed>
     */
    public function beforeForClient(): array
    {
        $before = is_array($this->before) ? $this->before : [];

        if ($before === []) {
            return [];
        }

        $class = $this->subjectClass();
        $redacted = $class ? (new $class)->revisionRedactedAttributes() : [];

        foreach ($before as $key => $value) {
            if (in_array($key, $redacted, true)) {
                $before[$key] = $value === null || $value === '' ? null : '(hidden)';
            } elseif (is_array($value)) {
                $before[$key] = json_encode($value);
            } elseif (is_string($value) && mb_strlen($value) > 300) {
                $before[$key] = mb_substr($value, 0, 300).'…';
            }
        }

        return $before;
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'actor' => $this->relationLoaded('actor') && $this->actor ? $this->actor->name : null,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_label' => $this->subjectLabel(),
            'subject_id' => $this->subject_id,
            'subject_name' => $this->subject_name,
            'summary' => $this->summary,
            'changed' => $this->changed ?: [],
            'before' => $this->beforeForClient(),
            'restorable' => $this->isRestorable(),
            'created_at' => $this->created_at,
        ];
    }
}
