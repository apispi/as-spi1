<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A public, read-only view over a chosen set of monitors — uptime, latency,
 * current state — at /status/{token}.
 *
 * The page shows exactly what its owner opted in, nothing else: monitor
 * names, pass/fail history, and timing. No URLs, no step detail, no owner
 * identity. An MCP drift monitor on a status page is the novel case: a public
 * page saying "this MCP server's tool contract is stable".
 */
class StatusPage extends Model
{
    public const MAX_PER_USER = 5;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'token',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    protected $attributes = [
        'is_enabled' => true,
    ];

    public static function generateToken(): string
    {
        return Str::lower(Str::random(40));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function monitors()
    {
        return $this->belongsToMany(Monitor::class)->orderByPivot('position');
    }
}
