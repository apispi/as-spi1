<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password', 'is_admin', 'scx_api_key', 'google_id', 'avatar'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token', 'scx_api_key', 'api_token', 'registration_token'];

    /**
     * Prefix on generated personal API keys, so they are recognisable.
     */
    public const API_KEY_PREFIX = 'spi_';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'scx_api_key' => 'encrypted',
            'preferences' => 'array',
            'api_token_created_at' => 'datetime',
            'registration_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Issue a new personal API key, replacing any existing one. Returns the
     * plaintext key, which is the only time it is available — only its hash
     * is stored.
     */
    public function generateApiKey(): string
    {
        $plain = self::API_KEY_PREFIX.Str::random(40);

        $this->forceFill([
            'api_token' => self::hashApiKey($plain),
            'api_token_last_four' => substr($plain, -4),
            'api_token_created_at' => now(),
        ])->save();

        return $plain;
    }

    /**
     * The key is high-entropy random, so a fast hash is appropriate here;
     * bcrypt would only add cost without meaningful benefit.
     */
    public static function hashApiKey(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByApiKey(string $plain): ?self
    {
        return static::where('api_token', self::hashApiKey($plain))->first();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function savedRequests()
    {
        return $this->hasMany(SavedRequest::class);
    }

    public function requestHistories()
    {
        return $this->hasMany(RequestHistory::class);
    }
}
