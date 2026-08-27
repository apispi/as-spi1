<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorResult extends Model
{
    protected $fillable = [
        'monitor_id',
        'inspection_report_id',
        'passed',
        'time_ms',
        'passed_count',
        'total',
        'summary',
    ];

    protected $casts = [
        'passed' => 'boolean',
    ];

    /**
     * The tools/list snapshot stored with this result's report, when it was a
     * drift run. Null for collection runs and unreachable drift runs.
     */
    public function driftSnapshot(): ?array
    {
        $report = \App\Models\InspectionReport::find($this->inspection_report_id);

        return $report?->data['snapshot'] ?? null;
    }

    public function monitor()
    {
        return $this->belongsTo(Monitor::class);
    }
}
