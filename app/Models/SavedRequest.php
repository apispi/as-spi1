<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\SharedInWorkspace;
use Illuminate\Database\Eloquent\Model;

class SavedRequest extends Model
{
    use RecordsActivity, SharedInWorkspace;

    /** Capturing a snapshot is its own action, not an edit to the request. */
    protected array $activityIgnored = ['snapshot', 'snapshot_taken_at'];

    /** Auth carries a credential, headers may carry one by hand, and a snapshot is a captured response body. */
    protected array $revisionRedacted = ['auth', 'headers', 'snapshot'];

    protected $fillable = [
        'user_id',
        'name',
        'protocol',
        'method',
        'url',
        'headers',
        'auth',
        'body',
        'params',
        'assertions',
        'contract',
        'snapshot',
        'snapshot_taken_at',
    ];

    protected $casts = [
        'headers' => 'array',
        'auth' => 'array',
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
