<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAction extends Model
{
    protected $fillable = [
        'admin_id',
        'admin_email',
        'action',
        'target_user_id',
        'target_email',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
