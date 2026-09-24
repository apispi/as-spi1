<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The previous values behind each logged change, so the feed can answer
     * "what was it before?" and put it back.
     */
    public function up(): void
    {
        Schema::table('workspace_activity', function (Blueprint $table) {
            $table->json('changed')->nullable()->after('summary');
            $table->json('before')->nullable()->after('changed');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_activity', function (Blueprint $table) {
            $table->dropColumn(['changed', 'before']);
        });
    }
};
