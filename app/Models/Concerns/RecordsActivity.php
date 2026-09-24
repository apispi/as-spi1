<?php

namespace App\Models\Concerns;

use App\Models\WorkspaceActivity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Logs create/update/delete of a shared resource to the workspace activity
 * feed, attributed to whoever is signed in, and keeps the values that were
 * replaced so a change can be inspected and undone.
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
 *
 * The stored previous values live in the same database, under the same
 * workspace scoping, as the row they came from — so keeping them is not a new
 * exposure. What must not happen is echoing a credential back to a browser
 * that would never have been sent one, so attributes named in
 * $revisionRedacted are withheld from the API while still being restorable
 * server-side.
 */
trait RecordsActivity
{
    /** Columns that churn on their own and never represent an edit. */
    protected array $activityIgnoredDefaults = ['updated_at', 'created_at'];

    /**
     * Beyond this, a stored snapshot is more burden than undo is worth — a
     * large request body or a hundred-variable environment would bloat every
     * row of the feed. The change is still logged; only the undo is dropped.
     */
    protected int $revisionMaxBytes = 65536;

    public static function bootRecordsActivity(): void
    {
        static::created(fn ($model) => $model->recordActivity(WorkspaceActivity::ACTION_CREATED));

        static::updated(function ($model) {
            $changed = $model->meaningfulActivityChanges();

            if ($changed === []) {
                return;
            }

            $model->recordActivity(
                WorkspaceActivity::ACTION_UPDATED,
                'changed '.implode(', ', array_slice($changed, 0, 4))
                    .(count($changed) > 4 ? ' and '.(count($changed) - 4).' more' : ''),
                $changed,
                // getOriginal() still holds the pre-save values here, which is
                // exactly what an undo needs to put back.
                $model->revisionPayload(array_intersect_key($model->getOriginal(), array_flip($changed)))
            );
        });

        static::deleted(function ($model) {
            $attributes = $model->getOriginal() ?: $model->getAttributes();

            $model->recordActivity(
                WorkspaceActivity::ACTION_DELETED,
                null,
                array_values(array_diff(array_keys($attributes), $model->activityIgnoredAttributes())),
                $model->revisionPayload($attributes)
            );
        });
    }

    /**
     * The attributes this update actually changed, ignoring bookkeeping.
     *
     * @return array<int, string>
     */
    public function meaningfulActivityChanges(): array
    {
        return array_values(array_diff(
            array_keys($this->getChanges()),
            $this->activityIgnoredAttributes()
        ));
    }

    /** @return array<int, string> */
    public function activityIgnoredAttributes(): array
    {
        return array_merge($this->activityIgnoredDefaults, $this->activityIgnored ?? []);
    }

    /**
     * Attributes whose stored values must never be sent to a client — secret
     * environment values, auth credentials, captured response bodies.
     *
     * @return array<int, string>
     */
    public function revisionRedactedAttributes(): array
    {
        return $this->revisionRedacted ?? [];
    }

    /**
     * The snapshot to keep, or null when it is too large to be worth storing.
     */
    protected function revisionPayload(array $attributes): ?array
    {
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);

        if ($attributes === []) {
            return null;
        }

        $encoded = json_encode($attributes);

        return $encoded !== false && strlen($encoded) <= $this->revisionMaxBytes ? $attributes : null;
    }

    protected function recordActivity(string $action, ?string $summary = null, ?array $changed = null, ?array $before = null): void
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
            $summary,
            $changed,
            $before
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
