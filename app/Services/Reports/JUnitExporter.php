<?php

namespace App\Services\Reports;

/**
 * Renders a run result as JUnit XML — the format every CI system already knows
 * how to display. A collection run becomes a test suite of steps; a dataset run
 * becomes a suite of rows. That turns "the pipeline went red" into an annotated
 * list of which step failed and why, inside the CI UI, with no extra tooling.
 *
 * Only run-shaped reports have a meaningful test-case mapping, so anything else
 * is refused rather than emitted as an empty suite that would show a misleading
 * green tick.
 */
class JUnitExporter
{
    /** Report types that map onto test cases. */
    public const SUPPORTED = ['collection_run', 'dataset_run'];

    public function supports(string $type): bool
    {
        return in_array($type, self::SUPPORTED, true);
    }

    /**
     * @param  array  $data  the run result (a collection_run or dataset_run payload)
     */
    public function render(string $type, array $data): string
    {
        return $type === 'dataset_run'
            ? $this->datasetRun($data)
            : $this->collectionRun($data);
    }

    /**
     * One suite for the collection, one test case per step. A skipped step (one
     * that never ran because an earlier step failed) is emitted as <skipped>,
     * which keeps the failure count pointing at the actual cause.
     */
    private function collectionRun(array $data): string
    {
        $name = (string) ($data['collection']['name'] ?? 'Collection run');
        $environment = $data['environment']['name'] ?? null;
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];

        $cases = [];
        $failures = 0;
        $skipped = 0;

        foreach ($steps as $step) {
            $isSkipped = (bool) ($step['skipped'] ?? false);
            $passed = (bool) ($step['passed'] ?? false);

            $case = $this->openCase(
                $this->caseName($step),
                $name,
                ((int) ($step['time_ms'] ?? 0)) / 1000
            );

            if ($isSkipped) {
                $skipped++;
                $case .= '    <skipped message="'.$this->attr((string) ($step['error'] ?? 'Skipped')).'"/>'."\n";
            } elseif (! $passed) {
                $failures++;
                $case .= $this->failureElement($this->failureMessage($step), $this->failureDetail($step));
            }

            $case .= '  </testcase>'."\n";
            $cases[] = $case;
        }

        $suiteName = $environment ? $name.' ('.$environment.')' : $name;

        return $this->document(
            $suiteName,
            count($cases),
            $failures,
            $skipped,
            ((int) ($data['time_ms'] ?? 0)) / 1000,
            $cases
        );
    }

    /**
     * One test case per dataset row. The row payload is deliberately not
     * included — a dataset can carry credentials, and the report it comes from
     * only ever stored the variable names.
     */
    private function datasetRun(array $data): string
    {
        $name = (string) ($data['collection']['name'] ?? 'Dataset run');
        $environment = $data['environment']['name'] ?? null;
        $iterations = is_array($data['iterations'] ?? null) ? $data['iterations'] : [];

        $cases = [];
        $failures = 0;

        foreach ($iterations as $iteration) {
            $row = (int) ($iteration['row'] ?? count($cases) + 1);
            $case = $this->openCase('Row '.$row, $name, 0.0);

            if (! ($iteration['passed'] ?? false)) {
                $failures++;
                $first = $iteration['first_failure'] ?? null;
                $message = sprintf(
                    '%d of %d step(s) passed%s',
                    (int) ($iteration['passed_count'] ?? 0),
                    (int) ($iteration['total'] ?? 0),
                    $first ? ' — first failure: '.$first : ''
                );
                $variables = is_array($iteration['variables'] ?? null) ? $iteration['variables'] : [];
                $detail = $variables === [] ? '' : 'Variables: '.implode(', ', $variables);
                $case .= $this->failureElement($message, $detail);
            }

            $case .= '  </testcase>'."\n";
            $cases[] = $case;
        }

        $suiteName = ($environment ? $name.' ('.$environment.')' : $name).' — dataset';

        return $this->document($suiteName, count($cases), $failures, 0, 0.0, $cases);
    }

    /** A step's test-case name: "GET Fetch user", falling back to its index. */
    private function caseName(array $step): string
    {
        $label = trim((string) ($step['name'] ?? ''));
        if ($label === '') {
            $label = 'Step '.((int) ($step['index'] ?? 0) + 1);
        }

        $method = trim((string) ($step['method'] ?? ''));

        return $method !== '' ? $method.' '.$label : $label;
    }

    /** A one-line reason the step failed, preferring the most specific cause. */
    private function failureMessage(array $step): string
    {
        if (! empty($step['error'])) {
            return (string) $step['error'];
        }

        $failed = $this->failedAssertions($step);
        if ($failed !== []) {
            return count($failed).' assertion(s) failed';
        }

        if (isset($step['contract']) && is_array($step['contract']) && ($step['contract']['conforms'] ?? true) === false) {
            return 'Response contract drift';
        }

        $status = $step['status'] ?? null;

        return $status !== null ? 'Step failed (HTTP '.$status.')' : 'Step failed';
    }

    /** The body of the <failure> element: what was checked, and what came back. */
    private function failureDetail(array $step): string
    {
        $lines = [];

        if (! empty($step['url'])) {
            $lines[] = trim((string) ($step['method'] ?? '').' '.$step['url']);
        }
        if (($step['status'] ?? null) !== null) {
            $lines[] = 'Status: '.$step['status'];
        }

        foreach ($this->failedAssertions($step) as $assertion) {
            $description = $assertion['description'] ?? null;
            $lines[] = sprintf(
                '✗ %s %s %s — actual: %s%s',
                (string) ($assertion['path'] ?? '?'),
                (string) ($assertion['operator'] ?? '?'),
                $this->scalar($assertion['expected'] ?? null),
                $this->scalar($assertion['actual'] ?? null),
                $description ? ' ('.$description.')' : ''
            );
            if (! empty($assertion['error'])) {
                $lines[] = '  '.$assertion['error'];
            }
        }

        $unresolved = is_array($step['unresolved'] ?? null) ? $step['unresolved'] : [];
        if ($unresolved !== []) {
            $lines[] = 'Unresolved variables: '.implode(', ', $unresolved);
        }

        return implode("\n", $lines);
    }

    /** @return array<int, array> */
    private function failedAssertions(array $step): array
    {
        $results = $step['assertions']['results'] ?? null;

        if (! is_array($results)) {
            return [];
        }

        return array_values(array_filter($results, fn ($r) => is_array($r) && ! ($r['passed'] ?? false)));
    }

    private function openCase(string $name, string $classname, float $seconds): string
    {
        return sprintf(
            '  <testcase name="%s" classname="%s" time="%s">'."\n",
            $this->attr($name),
            $this->attr($classname),
            number_format($seconds, 3, '.', '')
        );
    }

    private function failureElement(string $message, string $detail): string
    {
        $element = '    <failure message="'.$this->attr($message).'" type="AssertionFailure">';

        if ($detail !== '') {
            $element .= $this->cdata($detail);
        }

        return $element.'</failure>'."\n";
    }

    /**
     * @param  array<int, string>  $cases
     */
    private function document(string $suiteName, int $tests, int $failures, int $skipped, float $seconds, array $cases): string
    {
        $time = number_format($seconds, 3, '.', '');
        $attributes = sprintf(
            'name="%s" tests="%d" failures="%d" errors="0" skipped="%d" time="%s"',
            $this->attr($suiteName),
            $tests,
            $failures,
            $skipped,
            $time
        );

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<testsuites '.$attributes.'>'."\n"
            .'<testsuite '.$attributes.' timestamp="'.gmdate('Y-m-d\TH:i:s').'">'."\n"
            .implode('', $cases)
            .'</testsuite>'."\n"
            .'</testsuites>'."\n";
    }

    /** Escape a value for use in an XML attribute. */
    private function attr(string $value): string
    {
        // Control characters are illegal in XML 1.0 even when escaped.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Wrap free text in CDATA, neutralising any "]]>" it contains. */
    private function cdata(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $value).']]>';
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '(unprintable)';
    }
}
