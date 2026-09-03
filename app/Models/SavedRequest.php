<?php

namespace App\Models;

use App\Models\Concerns\SharedInWorkspace;
use Illuminate\Database\Eloquent\Model;

class SavedRequest extends Model
{
    use SharedInWorkspace;

    protected $fillable = [
        'user_id',
        'name',
        'protocol',
        'method',
        'url',
        'headers',
        'body',
        'params',
        'assertions',
        'contract',
        'snapshot',
        'snapshot_taken_at',
    ];

    protected $casts = [
        'headers' => 'array',
        'params' => 'array',
        'assertions' => 'array',
        'contract' => 'array',
        'snapshot' => 'array',
        'snapshot_taken_at' => 'datetime',
    ];

    // The golden body can be large; keep it out of API responses. The client
    // only needs snapshot_taken_at (a separate column) to know one exists.
    protected $hidden = ['snapshot'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
