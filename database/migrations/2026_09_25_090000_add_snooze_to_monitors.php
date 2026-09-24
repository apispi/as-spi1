<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->after('alerts_enabled');
            // Which status was last *announced*, as opposed to last observed.
            // Alerts fire on a change in this, so a transition that happens
            // while snoozed is delayed rather than lost.
            $table->string('last_alerted_status', 20)->nullable()->after('last_status');
        });

        // Existing monitors have already alerted on whatever they last saw.
        DB::table('monitors')->update(['last_alerted_status' => DB::raw('last_status')]);
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn(['snoozed_until', 'last_alerted_status']);
        });
    }
};
