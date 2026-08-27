<?php

namespace App\Models;

use App\Models\Concerns\SharedInWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The MCP flight recorder: a pass-through endpoint at /mcp-proxy/{token} that
 * relays an agent's traffic to a real MCP server and records every exchange.
 *
 * Everything else in Spi tests a server directly; this records what an agent
 * actually DID with one — which tools it called, with what arguments, what
 * came back, and whether a response tried to inject instructions. Point the
 * agent at the proxy URL instead of the server and the conversation becomes
 * inspectable, replayable, and scanned live.
 */
class McpProxy extends Model
{
    use SharedInWorkspace;

    public const MAX_PER_USER = 10;

    /** Exchanges kept per proxy; older ones are trimmed. */
    public const RETENTION = 200;

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'upstream_url',
        'policy',
        'is_enabled',
        'last_used_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'policy' => 'array',
        'last_used_at' => 'datetime',
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

    public function exchanges()
    {
        return $this->hasMany(McpProxyExchange::class)->latest('id');
    }
}
