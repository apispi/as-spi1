<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A customer organisation that users belong to — and, since
 * User::workspaceUserIds() reads organisation_id, the boundary of a shared
 * workspace: members see each other's saved requests, collections,
 * environments, monitors and reports.
 *
 * It is a sharing boundary, not an isolation one. Nothing here restricts what a
 * member may do with what they can see, and platform admins are outside it
 * entirely.
 */
class Organisation extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'owner_user_id',
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

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Who may remove members and revoke invitations.
     *
     * Organisations created by an invitation record their creator. Ones created
     * in the admin area predate that and have none, so the earliest member
     * stands in — an arbitrary rule, but a stable one, and better than a team
     * nobody can ever remove anyone from.
     */
    public function ownerId(): ?int
    {
        return $this->owner_user_id ?? $this->users()->orderBy('id')->value('id');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->ownerId() === $user->id;
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
