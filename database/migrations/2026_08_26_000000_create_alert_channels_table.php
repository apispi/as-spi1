<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // slack | discord | webhook
            $table->string('type')->default('webhook');
            // Where to POST. Validated as publicly routable on write, and the
            // address is pinned again at delivery time.
            $table->text('url');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        // Which channels a monitor alerts on. Many-to-many so one Slack channel
        // can serve every monitor without being re-entered.
        Schema::create('alert_channel_monitor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();

            $table->unique(['alert_channel_id', 'monitor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_channel_monitor');
        Schema::dropIfExists('alert_channels');
    }
};
