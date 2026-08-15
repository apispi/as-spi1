<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestHistory extends Model
{
    /**
     * Number of history entries kept per user; older entries are trimmed.
     */
    public const RETENTION_PER_USER = 200;

    protected $fillable = [
        'user_id',
        'protocol',
        'method',
        'url',
        'params',
        'body',
        'status',
        'time_ms',
    ];

    protected $casts = [
        'params' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a request for a user and trim their history to the retention cap.
     */
    public static function record(int $userId, array $attributes): void
    {
        // Requests resolved from an environment may carry secret values in the
        // URL, body, or params. History is long-lived and reloadable, so store
        // the masked form rather than the credential.
        $masker = app(\App\Services\Variables\SecretMasker::class);

        if ($masker->hasSecrets()) {
            foreach (['url', 'body', 'params'] as $key) {
                if (isset($attributes[$key])) {
                    $attributes[$key] = $masker->mask($attributes[$key]);
                }
            }
        }

        static::create($attributes + ['user_id' => $userId]);

        $cutoff = static::where('user_id', $userId)
            ->orderByDesc('id')
            ->skip(static::RETENTION_PER_USER)
            ->value('id');

        if ($cutoff !== null) {
            static::where('user_id', $userId)->where('id', '<=', $cutoff)->delete();
        }
    }
}
