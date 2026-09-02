<?php

namespace App\Services\Collections;

use App\Models\Collection;
use App\Models\Environment;
use App\Services\Assertions\AssertionEvaluator;
use App\Services\Contracts\ContractChecker;
use App\Services\Variables\SecretMasker;
use App\Services\Variables\VariableResolver;
use Illuminate\Support\Arr;

/**
 * Runs a collection start to finish against one environment.
 *
 * The variable pool starts as the environment's variables and grows as steps
 * extract values from their responses, so a login step can hand a token to
 * every step after it. Resolution happens per step, immediately before
 * sending, which is what makes that threading work.
 */
class CollectionRunner
{
    public function __construct(
        private readonly RequestExecutor $executor,
        private readonly AssertionEvaluator $evaluator,
        private readonly SecretMasker $masker,
        private readonly ContractChecker $contracts = new ContractChecker,
    ) {
    }

    public function run(Collection $collection, ?Environment $environment = null, bool $captureBodies = false, array $extraVariables = []): array
    {
        // Extra variables (e.g. from a webhook payload) override the
        // environment, so a triggered run can target the specific record the
        // callback named.
        $variables = array_merge($environment?->map() ?? [], $extraVariables);

        if ($environment) {
            $this->masker->remember($environment->secretValues());
        }

        $steps = [];
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $startedAt = microtime(true);

        foreach ($collection->steps()->with('savedRequest')->get() as $index => $step) {
            $saved = $step->savedRequest;

            if (! $saved) {
                continue;
            }

            // Once a step has failed and the collection stops on failure, the
            // rest are reported as skipped rather than silently dropped.
            if ($failed > 0 && ! $collection->continue_on_failure) {
                $steps[] = $this->skippedStep($index, $saved->name);
                $skipped++;

                continue;
            }

            $result = $this->runStep($step, $saved, $variables, $index, $captureBodies);

            $steps[] = $result['step'];
            $variables = $result['variables'];

            $result['step']['passed'] ? $passed++ : $failed++;
        }

        // A run with nothing in it is not a pass. Reporting green for a
        // collection whose steps have all been deleted would make a monitor
        // silently healthy while checking nothing at all.
        $ranNothing = $steps === [];

        return [
            'passed' => $failed === 0 && ! $ranNothing,
            'error' => $ranNothing ? 'This collection has no steps to run.' : null,
            'collection' => ['id' => $collection->id, 'name' => $collection->name],
            'environment' => $environment ? ['id' => $environment->id, 'name' => $environment->name] : null,
            'total' => count($steps),
            'passed_count' => $passed,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'steps' => $steps,
        ];
    }

    private function runStep($step, $saved, array $variables, int $index, bool $captureBodies = false): array
    {
        $resolver = new VariableResolver;

        $request = $resolver->resolve([
            'protocol' => $saved->protocol ?: 'rest',
            'method' => $saved->method,
            'url' => $saved->url,
            'headers' => $saved->headers ?? [],
            'body' => $saved->body,
            'params' => $saved->params ?? [],
        ], $variables);

        $response = $this->executor->send($request);

        $assertions = $saved->assertions ?? [];
        $evaluation = $assertions
            ? $this->evaluator->evaluate($assertions, $response)
            : null;

        // Contract drift: if a schema baseline is attached, check the live
        // response against it. Breaking drift (a removed required field or a
        // type change) fails the step, catching silent breaks no assertion was
        // written for. Additive drift is reported but does not fail.
        $contract = ! empty($saved->contract) && $response['ok']
            ? $this->contracts->fromBody($saved->contract, is_string($response['body'] ?? null) ? $response['body'] : json_encode($response['body'] ?? null))
            : null;

        $passed = $response['ok']
            && ($evaluation === null || $evaluation['passed'])
            && ($contract === null || ! $contract['breaking']);

        $extracted = [];
        if ($response['ok']) {
            [$variables, $extracted] = $this->extract($step->extract ?? [], $response, $variables);
        }

        return [
            'variables' => $variables,
            'step' => [
                'index' => $index,
                'name' => $saved->name,
                'protocol' => $saved->protocol ?: 'rest',
                'method' => $saved->method,
                // Masked: a resolved URL may carry a secret variable, and a
                // run is persisted as a report that can be shared.
                'url' => $this->masker->mask($request['url'] ?? ''),
                'status' => $response['status'],
                'time_ms' => $response['time_ms'],
                'error' => $response['error'],
                'unresolved' => $resolver->unresolved(),
                'assertions' => $evaluation ? $this->masker->mask($evaluation) : null,
                'contract' => $contract,
                'body' => $captureBodies ? $this->masker->mask(is_string($response['body'] ?? null) ? $response['body'] : json_encode($response['body'] ?? null)) : null,
                'extracted' => array_keys($extracted),
                'passed' => $passed,
                'skipped' => false,
            ],
        ];
    }

    /**
     * Pull values out of a response into the variable pool for later steps.
     *
     * @return array{0: array, 1: array}
     */
    private function extract(array $rules, array $response, array $variables): array
    {
        $decoded = is_string($response['body'] ?? null)
            ? json_decode($response['body'], true)
            : ($response['body'] ?? null);

        $extracted = [];

        foreach ($rules as $rule) {
            $name = trim((string) ($rule['name'] ?? ''));
            $path = trim((string) ($rule['path'] ?? ''));

            if ($name === '' || $path === '') {
                continue;
            }

            $value = match (true) {
                $path === 'status' => $response['status'],
                $path === 'time_ms' => $response['time_ms'],
                str_starts_with($path, 'header.') => $this->header($response['headers'] ?? [], substr($path, 7)),
                is_array($decoded) => Arr::get($decoded, $this->normalise($path)),
                default => null,
            };

            if ($value === null || is_array($value)) {
                // Only scalars can be substituted into a later request.
                continue;
            }

            $variables[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $extracted[$name] = $variables[$name];
        }

        return [$variables, $extracted];
    }

    private function normalise(string $path): string
    {
        $path = preg_replace('/^\$\.?/', '', $path);

        return str_replace(['[', ']'], ['.', ''], $path);
    }

    private function header(array $headers, string $name): mixed
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }

    private function skippedStep(int $index, string $name): array
    {
        return [
            'index' => $index,
            'name' => $name,
            'protocol' => null,
            'method' => null,
            'url' => null,
            'status' => null,
            'time_ms' => 0,
            'error' => 'Skipped after an earlier step failed.',
            'unresolved' => [],
            'assertions' => null,
            'extracted' => [],
            'passed' => false,
            'skipped' => true,
        ];
    }
}
