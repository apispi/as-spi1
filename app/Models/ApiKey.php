<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A named, revocable personal API key for the /api/v1 programmatic routes and
 * the MCP gateway.
 *
 * A user can hold several — one per integration (CI, an agent, a script) — so
 * one can be revoked without breaking the others. Keys are personal
 * credentials, NOT workspace-shared. Only the SHA-256 hash is stored; the
 * plaintext is shown once at creation.
 */
class ApiKey extends Model
{
    public const MAX_PER_USER = 20;

    public const PREFIX = 'spi_';

    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'last_four',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Create a key for a user and return [model, plaintext]. The plaintext is
     * the only time the full key exists.
     */
    public static function issue(User $user, string $name, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        $key = $user->apiKeys()->create([
            'name' => $name,
            'token_hash' => self::hash($plain),
            'last_four' => substr($plain, -4),
            'expires_at' => $expiresAt,
        ]);

        return [$key, $plain];
    }

    /**
     * Resolve a live (not revoked, not expired) key by its plaintext.
     */
    public static function resolve(string $plain): ?self
    {
        return static::where('token_hash', self::hash($plain))
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'masked' => self::PREFIX.str_repeat('•', 8).$this->last_four,
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
            'revoked' => $this->revoked_at !== null,
            'expired' => $this->expires_at !== null && $this->expires_at->isPast(),
            'created_at' => $this->created_at,
        ];
    }
}
