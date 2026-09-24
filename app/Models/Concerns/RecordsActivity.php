<?php

namespace App\Models\Concerns;

use App\Models\WorkspaceActivity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Logs create/update/delete of a shared resource to the workspace activity
 * feed, attributed to whoever is signed in.
 *
 * Two rules keep the feed worth reading:
 *
 * 1. Nothing is recorded without a signed-in actor. Monitors, webhook checks
 *    and seeders all write to these tables from the scheduler or the console,
 *    and "somebody changed this monitor" every sixty seconds is exactly the
 *    noise that makes a feed get ignored.
 * 2. An update touching only bookkeeping columns is not a change anyone made.
 *    A monitor run writes last_run_at and last_status on every tick; those are
 *    listed in $activityIgnored and an update confined to them records nothing.
 */
trait RecordsActivity
{
    /** Columns that churn on their own and never represent an edit. */
    protected array $activityIgnoredDefaults = ['updated_at', 'created_at'];

    public static function bootRecordsActivity(): void
    {
        static::created(fn ($model) => $model->recordActivity(WorkspaceActivity::ACTION_CREATED));

        static::updated(function ($model) {
            $changed = $model->meaningfulActivityChanges();

            if ($changed !== []) {
                $model->recordActivity(
                    WorkspaceActivity::ACTION_UPDATED,
                    'changed '.implode(', ', array_slice($changed, 0, 4))
                        .(count($changed) > 4 ? ' and '.(count($changed) - 4).' more' : '')
                );
            }
        });

        static::deleted(fn ($model) => $model->recordActivity(WorkspaceActivity::ACTION_DELETED));
    }

    /**
     * The attributes this update actually changed, ignoring bookkeeping.
     *
     * @return array<int, string>
     */
    public function meaningfulActivityChanges(): array
    {
        $ignored = array_merge($this->activityIgnoredDefaults, $this->activityIgnored ?? []);

        return array_values(array_diff(array_keys($this->getChanges()), $ignored));
    }

    protected function recordActivity(string $action, ?string $summary = null): void
    {
        $actorId = Auth::id();

        if (! $actorId) {
            return;
        }

        WorkspaceActivity::record(
            $actorId,
            $this->activityType(),
            (int) $this->getKey(),
            $this->activityName(),
            $action,
            $summary
        );
    }

    /** A stable slug for the kind of resource, e.g. "saved_request". */
    protected function activityType(): string
    {
        return Str::snake(class_basename($this));
    }

    /** What to call this row in the feed. */
    protected function activityName(): ?string
    {
        return isset($this->attributes['name']) ? (string) $this->attributes['name'] : null;
    }
}
