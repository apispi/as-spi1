<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    /**
     * The entity types the catalog holds. Each maps to a tab in the Catalog
     * and Active admin sections.
     */
    public const TYPES = ['agent', 'skill', 'connector', 'tool', 'prompt'];

    protected $fillable = [
        'type',
        'name',
        'slug',
        'description',
        'version',
        'provider',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
