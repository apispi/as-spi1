<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An in-app notification for one user — surfaced in the top-bar bell so a
 * monitor failure or regression is seen even when no email/webhook alert
 * channel is configured. Distinct from Laravel's notification system, which
 * this app uses only for outbound monitor alerts.
 */
class UserNotification extends Model
{
    protected $fillable = ['user_id', 'type', 'title', 'body', 'url', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    /** Keep the table from growing without bound: newest N per user are kept. */
    public const KEEP_PER_USER = 100;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a notification for a user and trim their history to the most
     * recent KEEP_PER_USER.
     */
    public static function record(int $userId, string $type, string $title, ?string $body = null, ?string $url = null): self
    {
        $note = static::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);

        $keepIds = static::where('user_id', $userId)->latest('id')->limit(self::KEEP_PER_USER)->pluck('id');
        static::where('user_id', $userId)->whereNotIn('id', $keepIds)->delete();

        return $note;
    }
}
