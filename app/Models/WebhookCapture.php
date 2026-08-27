<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookCapture extends Model
{
    protected $fillable = [
        'webhook_endpoint_id',
        'method',
        'headers',
        'query',
        'body',
        'ip',
    ];

    protected $casts = [
        'headers' => 'array',
        'query' => 'array',
    ];

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
