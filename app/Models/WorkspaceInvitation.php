<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An invitation to join an organisation — which, because organisation
 * membership is what User::workspaceUserIds() reads, is an invitation into a
 * shared workspace: accepting it grants the invitee access to everything the
 * existing members own, and exposes the invitee's own saved requests,
 * collections and environments to them in turn.
 *
 * That is a large enough grant that the invitation is addressed to a specific
 * email, carries a token that exists only inside the email we send, and
 * expires. The token is stored hashed, the way API keys and share tokens are.
 */
class WorkspaceInvitation extends Model
{
    /** Pending invitations per organisation. Bounds abuse of the mailer. */
    public const MAX_PENDING = 25;

    public const LIFETIME_DAYS = 7;

    protected $fillable = [
        'organisation_id',
        'invited_by_user_id',
        'email',
        'token_hash',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** A URL-safe token, returned raw once and stored only as a hash. */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Look an invitation up by the raw token from the link. */
    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', static::hash($token))->first();
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Only pending invitations — neither accepted nor timed out. */
    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /** The shape the workspace screen renders. The token is never included. */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'invited_by' => $this->relationLoaded('invitedBy') && $this->invitedBy
                ? $this->invitedBy->name
                : null,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
