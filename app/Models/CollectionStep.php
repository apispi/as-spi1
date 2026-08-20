<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionStep extends Model
{
    protected $fillable = [
        'collection_id',
        'saved_request_id',
        'position',
        'extract',
    ];

    protected $casts = [
        'extract' => 'array',
    ];

    public function collection()
    {
        return $this->belongsTo(Collection::class);
    }

    public function savedRequest()
    {
        return $this->belongsTo(SavedRequest::class);
    }
}
