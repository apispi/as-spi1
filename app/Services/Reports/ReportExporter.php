<?php

namespace App\Services\Reports;

use App\Models\InspectionReport;

/**
 * Renders an inspection report as a downloadable Markdown document, so a run
 * can be archived or pasted into an issue/PR outside Spi. JSON export is the
 * raw record and needs no rendering; this handles the human-readable form.
 */
class ReportExporter
{
    public function markdown(InspectionReport $report): string
    {
        $lines = [];
        $title = ucfirst(str_replace('_', ' ', $report->type));
        if ($report->connector_name) {
            $title .= ' — '.$report->connector_name;
        }
        $lines[] = '# '.$title;
        $lines[] = '';
        if ($report->summary) {
            $lines[] = '**Summary:** '.$report->summary;
        }
        $lines[] = '**Type:** `'.$report->type.'`  ';
        $lines[] = '**Generated:** '.($report->created_at?->toDayDateTimeString() ?? 'unknown');
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        $this->render($report->data ?? [], $lines, 2);

        $lines[] = '';
        $lines[] = '_Exported from Spi · apispi.com_';

        return implode("\n", $lines)."\n";
    }

    /**
     * Recursively render a decoded data structure into Markdown. Associative
     * arrays become headings + fields; lists of scalars become bullets; lists
     * of objects become subsections. Depth-bounded so a deep blob stays sane.
     */
    private function render(mixed $value, array &$lines, int $headingLevel): void
    {
        if (! is_array($value)) {
            $lines[] = $this->scalar($value);

            return;
        }

        if (array_is_list($value)) {
            foreach ($value as $i => $item) {
                if (is_array($item)) {
                    $lines[] = str_repeat('#', min($headingLevel, 6)).' Item '.($i + 1);
                    $this->render($item, $lines, $headingLevel + 1);
                    $lines[] = '';
                } else {
                    $lines[] = '- '.$this->scalar($item);
                }
            }

            return;
        }

        foreach ($value as $key => $item) {
            $label = $this->label($key);
            if (is_array($item) && $headingLevel <= 5) {
                $lines[] = str_repeat('#', min($headingLevel, 6)).' '.$label;
                $this->render($item, $lines, $headingLevel + 1);
                $lines[] = '';
            } elseif (is_array($item)) {
                $lines[] = '- **'.$label.':** '.$this->inlineArray($item);
            } else {
                $lines[] = '- **'.$label.':** '.$this->scalar($item);
            }
        }
    }

    private function label(string|int $key): string
    {
        return ucfirst(str_replace('_', ' ', (string) $key));
    }

    private function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return '_null_';
        }

        return (string) $v;
    }

    private function inlineArray(array $a): string
    {
        $json = json_encode($a);

        return '`'.(mb_strlen((string) $json) > 300 ? mb_substr((string) $json, 0, 297).'…' : $json).'`';
    }
}
