<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            // A captured "golden" response (status + body) this request is
            // expected to keep returning. Value-level regression, distinct from
            // the schema-level `contract` column.
            $table->json('snapshot')->nullable()->after('contract');
            $table->timestamp('snapshot_taken_at')->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            $table->dropColumn(['snapshot', 'snapshot_taken_at']);
        });
    }
};
