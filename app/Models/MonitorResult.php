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

    public function monitor()
    {
        return $this->belongsTo(Monitor::class);
    }
}
