<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedRequest extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'protocol',
        'method',
        'url',
        'headers',
        'body',
        'params',
    ];

    protected $casts = [
        'headers' => 'array',
        'params' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
