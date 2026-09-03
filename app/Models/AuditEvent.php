<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * A security-relevant action taken on an account — a login, an API key issued
 * or revoked, a password change, an account deletion. Complements AdminAction
 * (which records what admins do) with what account holders do, so a user can
 * review their own security history and an admin can review a user's.
 *
 * Append-only: recorded via record(), never updated. No updated_at column.
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'actor_email', 'action', 'ip', 'user_agent', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // The closed vocabulary of audited actions.
    public const ACTIONS = [
        'auth.login',
        'auth.login_failed',
        'auth.logout',
        'auth.register',
        'auth.password_changed',
        'api_key.created',
        'api_key.revoked',
        'account.deleted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an event. The actor may be a User, or just an email string for a
     * pre-auth event (a failed login). Request context (IP, user agent) is
     * captured when a request is given.
     */
    public static function record(string $action, User|string|null $actor = null, ?Request $request = null, array $metadata = []): self
    {
        $user = $actor instanceof User ? $actor : null;
        $email = $actor instanceof User ? $actor->email : $actor;

        return static::create([
            'user_id' => $user?->id,
            'actor_email' => $email,
            'action' => $action,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
            'metadata' => $metadata ?: null,
        ]);
    }

    /** A short, human label for the action. */
    public function label(): string
    {
        return [
            'auth.login' => 'Signed in',
            'auth.login_failed' => 'Failed sign-in',
            'auth.logout' => 'Signed out',
            'auth.register' => 'Account created',
            'auth.password_changed' => 'Password changed',
            'api_key.created' => 'API key created',
            'api_key.revoked' => 'API key revoked',
            'account.deleted' => 'Account deleted',
        ][$this->action] ?? $this->action;
    }
}
