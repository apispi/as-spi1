<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpProxyExchange extends Model
{
    protected $fillable = [
        'mcp_proxy_id',
        'method',
        'request',
        'response',
        'status',
        'duration_ms',
        'flagged',
        'flag_summary',
        'enforcement',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'flagged' => 'boolean',
        'enforcement' => 'array',
    ];

    public function proxy()
    {
        return $this->belongsTo(McpProxy::class, 'mcp_proxy_id');
    }
}
