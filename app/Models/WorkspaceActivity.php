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
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function record(int $userId, string $type, int $subjectId, ?string $name, string $action, ?string $summary = null): self
    {
        $entry = static::create([
            'user_id' => $userId,
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'subject_name' => $name !== null ? mb_substr($name, 0, 255) : null,
            'action' => $action,
            'summary' => $summary !== null ? mb_substr($summary, 0, 255) : null,
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
            'created_at' => $this->created_at,
        ];
    }
}
