<?php

namespace App\Models;

use App\Models\Concerns\SharedInWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * An ordered group of saved requests, run start to finish against one
 * environment. Values extracted from one step's response become variables for
 * later steps, so a login step can feed a token to everything after it.
 */
class Collection extends Model
{
    use SharedInWorkspace;

    public const MAX_PER_USER = 25;

    public const MAX_STEPS = 50;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'continue_on_failure',
    ];

    protected $casts = [
        'continue_on_failure' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function steps()
    {
        return $this->hasMany(CollectionStep::class)->orderBy('position');
    }
}
