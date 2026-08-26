<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A customer organisation that users belong to.
 *
 * Membership is informational for now — it groups users for administration and
 * reporting. It is deliberately NOT an authorisation boundary: nothing reads
 * organisation_id to decide who may see what, and saying otherwise would imply
 * an isolation guarantee the app does not yet enforce.
 */
class Organisation extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * A URL-safe slug that is unique across organisations.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'organisation';
        $slug = $base;
        $suffix = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
