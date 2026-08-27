<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * A resource that is owned by one user but shared across their organisation.
 *
 * The full-shared-workspace model: everyone in an organisation sees and uses
 * one pool of resources. A user with no organisation has a workspace of one —
 * themselves — so behaviour is unchanged for solo accounts.
 *
 * `user_id` still records the creator; scoping is what changes. Read, use,
 * edit, and delete all operate over the workspace, so `inWorkspaceOf` replaces
 * the old `$user->relation()` scoping. Creation stays owner-attributed, and
 * per-user creation caps stay per user.
 */
trait SharedInWorkspace
{
    /**
     * Limit a query to the resources visible in $user's workspace.
     */
    public function scopeInWorkspaceOf($query, User $user)
    {
        return $query->whereIn($this->getTable().'.user_id', $user->workspaceUserIds());
    }

    /**
     * The creator. Shared resources show whose they are, so the pool is not
     * anonymous.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
