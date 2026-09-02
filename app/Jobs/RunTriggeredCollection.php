<?php

namespace App\Jobs;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\InspectionReport;
use App\Services\Collections\CollectionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a collection because a webhook fired, off the request path so the
 * capture responds to the provider immediately.
 *
 * Queued: the webhook returns 200 at once and this executes when a worker picks
 * it up (like monitor alerts need SMTP, triggered runs need a queue worker). It
 * re-checks that the collection still belongs to the owner's workspace, so a
 * deleted or moved collection simply does nothing.
 */
class RunTriggeredCollection implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $ownerId,
        public int $collectionId,
        public ?int $environmentId,
        public array $variables,
        public string $endpointName,
    ) {
    }

    public function handle(CollectionRunner $runner): void
    {
        $collection = Collection::with('owner')->find($this->collectionId);

        // Owner or a workspace colleague must still be able to see it.
        if (! $collection || ! $collection->owner
            || ! in_array($collection->user_id, $collection->owner->workspaceUserIds(), true)) {
            return;
        }

        $environment = $this->environmentId
            ? Environment::find($this->environmentId)
            : null;

        $result = $runner->run($collection, $environment, false, $this->variables);

        InspectionReport::create([
            'user_id' => $this->ownerId,
            'type' => 'collection_run',
            'summary' => sprintf(
                '%s — %d/%d passed (webhook: %s)',
                $collection->name,
                $result['passed_count'],
                $result['total'],
                $this->endpointName
            ),
            'data' => $result + ['triggered_by' => $this->endpointName],
        ]);
    }
}
