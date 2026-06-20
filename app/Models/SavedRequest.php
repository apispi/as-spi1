<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedRequest extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'method',
        'url',
        'headers',
        'body',
    ];

    protected $casts = [
        'headers' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
