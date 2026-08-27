<?php

namespace App\Services\Collections;

use App\Services\Contracts\ContractChecker;
use App\Services\Contracts\SchemaInferrer;

/**
 * Compares two runs of the same collection against different environments and
 * reports where the responses diverge in SHAPE.
 *
 * The question is "does staging behave like production?" — so structural
 * divergence is the signal (a field one side returns and the other does not, a
 * field that is a number here and a string there). Value differences (ids,
 * timestamps) are expected between environments and are not treated as a
 * failure; only shape divergence is.
 *
 * Reuses the contract engine: infer a schema from side A's response and check
 * side B against it gives exactly the added/removed/type-changed diff.
 */
class ParityChecker
{
    public function __construct(
        private readonly SchemaInferrer $inferrer = new SchemaInferrer,
        private readonly ContractChecker $checker = new ContractChecker,
    ) {
    }

    /**
     * @param  array  $runA  a CollectionRunner result with captured bodies
     * @param  array  $runB  the same collection against the other environment
     */
    public function compare(array $runA, array $runB): array
    {
        $steps = [];
        $diverged = 0;

        $stepsB = collect($runB['steps'])->keyBy('index');

        foreach ($runA['steps'] as $stepA) {
            $stepB = $stepsB->get($stepA['index']);

            $diff = $this->diffStep($stepA, $stepB);
            if ($diff['diverged']) {
                $diverged++;
            }

            $steps[] = [
                'index' => $stepA['index'],
                'name' => $stepA['name'],
                'status_a' => $stepA['status'] ?? null,
                'status_b' => $stepB['status'] ?? null,
                'time_a_ms' => $stepA['time_ms'] ?? 0,
                'time_b_ms' => $stepB['time_ms'] ?? 0,
            ] + $diff;
        }

        return [
            'in_parity' => $diverged === 0,
            'diverged_count' => $diverged,
            'total' => count($steps),
            'environment_a' => $runA['environment'],
            'environment_b' => $runB['environment'],
            'steps' => $steps,
        ];
    }

    private function diffStep(array $stepA, ?array $stepB): array
    {
        if ($stepB === null) {
            return ['diverged' => true, 'reason' => 'Step ran on only one side.', 'shape' => null, 'status_differs' => true];
        }

        $statusDiffers = ($stepA['status'] ?? null) !== ($stepB['status'] ?? null);

        $schemaA = $this->inferrer->fromBody($stepA['body'] ?? null);
        $decodedB = isset($stepB['body']) ? json_decode((string) $stepB['body'], true) : null;

        // Neither side returned JSON — fall back to a raw-equality signal.
        if ($schemaA === null) {
            $bodiesDiffer = ($stepA['body'] ?? null) !== ($stepB['body'] ?? null);

            return [
                'diverged' => $statusDiffers,
                'status_differs' => $statusDiffers,
                'shape' => null,
                'note' => $bodiesDiffer ? 'Non-JSON bodies differ (values only).' : null,
            ];
        }

        $shape = $this->checker->check($schemaA, $decodedB);

        // Shape divergence OR a status mismatch means the environments are not
        // behaving the same.
        $diverged = $statusDiffers || ! $shape['conforms'];

        return [
            'diverged' => $diverged,
            'status_differs' => $statusDiffers,
            'shape' => [
                'only_in_a' => $shape['removed'],       // present in A's schema, missing in B
                'only_in_b' => $shape['added'],         // present in B, not in A
                'type_differs' => $shape['type_changed'],
            ],
        ];
    }
}
