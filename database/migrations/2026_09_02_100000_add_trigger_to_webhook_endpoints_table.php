<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            // When set, a captured request fires a run of this collection
            // (against the optional environment). Event-driven testing.
            $table->foreignId('trigger_collection_id')->nullable()->after('last_status')
                ->constrained('collections')->nullOnDelete();
            $table->foreignId('trigger_environment_id')->nullable()->after('trigger_collection_id')
                ->constrained('environments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trigger_collection_id');
            $table->dropConstrainedForeignId('trigger_environment_id');
        });
    }
};
