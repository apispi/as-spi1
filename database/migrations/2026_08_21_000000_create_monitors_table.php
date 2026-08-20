<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            // Nullable: a monitor may run without an environment, and the
            // environment being deleted should pause the monitor, not delete it.
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('interval_minutes')->default(60);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('alerts_enabled')->default(true);
            // passing | failing | unknown — "unknown" until the first run.
            $table->string('last_status')->default('unknown');
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            // The scheduler asks for enabled monitors ordered by staleness.
            $table->index(['is_enabled', 'last_run_at']);
            $table->unique(['user_id', 'name']);
        });

        Schema::create('monitor_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            // The full run is kept as a report; this row is the compact
            // history point that uptime and latency charts read.
            $table->foreignId('inspection_report_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('passed');
            $table->unsignedInteger('time_ms')->default(0);
            $table->unsignedSmallInteger('passed_count')->default(0);
            $table->unsignedSmallInteger('total')->default(0);
            $table->string('summary')->nullable();
            $table->timestamps();

            $table->index(['monitor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_results');
        Schema::dropIfExists('monitors');
    }
};
