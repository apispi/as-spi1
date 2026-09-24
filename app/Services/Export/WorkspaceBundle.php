<?php

namespace App\Services\Export;

use App\Http\Controllers\SavedRequestController;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\SavedRequest;
use App\Models\User;
use App\Services\Auth\RequestAuthenticator;
use Illuminate\Support\Facades\DB;

/**
 * A whole workspace in one document: its saved requests, the collections built
 * from them, and its environments.
 *
 * Per-resource export already exists — a collection as Postman, an environment
 * on its own — but none of it answers "give me everything so I can take it to
 * another account, or keep a copy". This does, and the import side puts it
 * back without disturbing what is already there.
 *
 * Credentials are never in the bundle. Secret variable values, auth tokens and
 * client secrets export as empty, exactly as the single-environment export
 * already does: a bundle is a thing people email to each other, and the whole
 * point of marking a value secret is that it does not travel.
 */
class WorkspaceBundle
{
    public const VERSION = '1.0';

    /** Bundles above this are refused rather than half-imported. */
    public const MAX_BYTES = 8388608;

    public function export(User $user): array
    {
        $requests = SavedRequest::inWorkspaceOf($user)->orderBy('id')->get();
        $collections = Collection::inWorkspaceOf($user)->with('steps')->orderBy('id')->get();
        $environments = Environment::inWorkspaceOf($user)->orderBy('id')->get();

        return [
            'spi_workspace' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'saved_requests' => $requests->map(fn ($r) => [
                // A stable handle for steps to refer to. Ids are not portable
                // between accounts, names are what a human recognises.
                'ref' => 'r'.$r->id,
                'name' => $r->name,
                'protocol' => $r->protocol,
                'method' => $r->method,
                'url' => $r->url,
                'headers' => $r->headers,
                'auth' => $this->strippedAuth($r->auth),
                'body' => $r->body,
                'params' => $r->params,
                'assertions' => $r->assertions,
                'contract' => $r->contract,
            ])->values()->all(),
            'collections' => $collections->map(fn ($c) => [
                'name' => $c->name,
                'description' => $c->description,
                'continue_on_failure' => (bool) $c->continue_on_failure,
                'steps' => $c->steps->sortBy('position')->map(fn ($s) => [
                    'ref' => 'r'.$s->saved_request_id,
                    'extract' => $s->extract,
                ])->values()->all(),
            ])->values()->all(),
            'environments' => $environments->map(fn ($e) => [
                'name' => $e->name,
                'is_default' => (bool) $e->is_default,
                'variables' => collect($e->variables ?? [])
                    ->filter(fn ($v) => is_array($v) && ($v['key'] ?? '') !== '')
                    ->map(fn ($v) => [
                        'key' => (string) $v['key'],
                        // A secret exports as its name and its flag, never its
                        // value — the importer re-enters the credential.
                        'value' => ! empty($v['secret']) ? '' : (string) ($v['value'] ?? ''),
                        'secret' => ! empty($v['secret']),
                    ])->values()->all(),
                'auth' => $this->strippedAuth($e->auth),
            ])->values()->all(),
        ];
    }

    /**
     * Create everything in the bundle, owned by $user.
     *
     * Additive by design: nothing existing is overwritten or deleted, and a
     * name that clashes gets a suffix. An import that silently replaced a
     * colleague's collection because the names matched would be a far worse
     * outcome than two collections with similar names.
     *
     * @return array{created: array<string,int>, skipped: array<int,string>}
     */
    public function import(User $user, array $bundle): array
    {
        $created = ['saved_requests' => 0, 'collections' => 0, 'environments' => 0];
        $skipped = [];

        return DB::transaction(function () use ($user, $bundle, &$created, &$skipped) {
            // ref => new id, so a collection's steps find the requests this
            // import just made rather than whatever happens to share a name.
            $refs = [];

            $requestRoom = $this->room($user, SavedRequest::class, SavedRequestController::FREE_PLAN_LIMIT, $user->isAdmin());

            foreach ($bundle['saved_requests'] ?? [] as $row) {
                if (! is_array($row) || ($row['name'] ?? '') === '') {
                    continue;
                }

                if ($requestRoom !== null && $requestRoom <= 0) {
                    $skipped[] = 'Saved request "'.$row['name'].'" — the saved-request limit was reached.';

                    continue;
                }

                $saved = $user->savedRequests()->create([
                    'name' => $this->uniqueName(SavedRequest::class, $user, (string) $row['name'], 255),
                    'protocol' => $row['protocol'] ?? 'rest',
                    'method' => $row['method'] ?? 'GET',
                    'url' => (string) ($row['url'] ?? ''),
                    'headers' => $this->arrayOrNull($row['headers'] ?? null),
                    'auth' => $this->arrayOrNull($row['auth'] ?? null),
                    'body' => is_string($row['body'] ?? null) ? $row['body'] : null,
                    'params' => $this->arrayOrNull($row['params'] ?? null),
                    'assertions' => $this->arrayOrNull($row['assertions'] ?? null),
                    'contract' => $this->arrayOrNull($row['contract'] ?? null),
                ]);

                $created['saved_requests']++;
                $requestRoom !== null && $requestRoom--;

                if (isset($row['ref']) && is_string($row['ref'])) {
                    $refs[$row['ref']] = $saved->id;
                }
            }

            $collectionRoom = $this->room($user, Collection::class, Collection::MAX_PER_USER, false);

            foreach ($bundle['collections'] ?? [] as $row) {
                if (! is_array($row) || ($row['name'] ?? '') === '') {
                    continue;
                }

                if ($collectionRoom !== null && $collectionRoom <= 0) {
                    $skipped[] = 'Collection "'.$row['name'].'" — the collection limit was reached.';

                    continue;
                }

                $collection = $user->collections()->create([
                    'name' => $this->uniqueName(Collection::class, $user, (string) $row['name'], 80),
                    'description' => is_string($row['description'] ?? null) ? $row['description'] : null,
                    'continue_on_failure' => (bool) ($row['continue_on_failure'] ?? false),
                ]);

                $position = 0;
                foreach (array_slice($row['steps'] ?? [], 0, Collection::MAX_STEPS) as $step) {
                    $id = $refs[$step['ref'] ?? ''] ?? null;

                    // A step whose request was skipped (or never in the bundle)
                    // is dropped rather than pointed at something arbitrary.
                    if ($id === null) {
                        continue;
                    }

                    $collection->steps()->create([
                        'saved_request_id' => $id,
                        'position' => $position++,
                        'extract' => $this->arrayOrNull($step['extract'] ?? null) ?? [],
                    ]);
                }

                if ($position === 0 && ($row['steps'] ?? []) !== []) {
                    $skipped[] = 'Collection "'.$row['name'].'" imported with no steps — its requests were not in the bundle.';
                }

                $created['collections']++;
                $collectionRoom !== null && $collectionRoom--;
            }

            $environmentRoom = $this->room($user, Environment::class, Environment::MAX_PER_USER, false);

            foreach ($bundle['environments'] ?? [] as $row) {
                if (! is_array($row) || ($row['name'] ?? '') === '') {
                    continue;
                }

                if ($environmentRoom !== null && $environmentRoom <= 0) {
                    $skipped[] = 'Environment "'.$row['name'].'" — the environment limit was reached.';

                    continue;
                }

                $user->environments()->create([
                    'name' => $this->uniqueName(Environment::class, $user, (string) $row['name'], 60),
                    'variables' => collect($row['variables'] ?? [])
                        ->filter(fn ($v) => is_array($v) && ($v['key'] ?? '') !== '')
                        ->map(fn ($v) => [
                            'key' => (string) $v['key'],
                            'value' => (string) ($v['value'] ?? ''),
                            'secret' => ! empty($v['secret']),
                        ])->values()->all(),
                    'auth' => $this->arrayOrNull($row['auth'] ?? null),
                    // Never imported as the default: the importing account
                    // already has one, and silently moving it would re-point
                    // every request that relies on it.
                    'is_default' => false,
                ]);

                $created['environments']++;
                $environmentRoom !== null && $environmentRoom--;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /** How many more of this type may be created, or null when uncapped. */
    private function room(User $user, string $class, int $limit, bool $exempt): ?int
    {
        if ($exempt) {
            return null;
        }

        return max(0, $limit - $class::where('user_id', $user->id)->count());
    }

    /**
     * A name nothing in the workspace is already using. Importing twice gives
     * "Checkout (imported)" and then "Checkout (imported 2)", which is honest
     * about what happened.
     */
    private function uniqueName(string $class, User $user, string $base, int $max): string
    {
        $base = trim($base) !== '' ? trim($base) : 'Imported';

        if (! $class::inWorkspaceOf($user)->where('name', $base)->exists()) {
            return mb_substr($base, 0, $max);
        }

        $name = $base.' (imported)';
        $i = 2;

        while ($class::inWorkspaceOf($user)->where('name', mb_substr($name, 0, $max))->exists()) {
            $name = $base.' (imported '.$i++.')';
        }

        return mb_substr($name, 0, $max);
    }

    /** Auth config with every credential-bearing field emptied. */
    private function strippedAuth(mixed $auth): ?array
    {
        if (! is_array($auth) || $auth === []) {
            return null;
        }

        foreach (RequestAuthenticator::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $auth)) {
                $auth[$field] = '';
            }
        }

        return $auth;
    }

    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }
}
