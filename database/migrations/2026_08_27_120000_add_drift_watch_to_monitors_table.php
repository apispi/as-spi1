<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            // collection | mcp_drift. A drift monitor watches an MCP server's
            // tools/list for shape changes instead of running a collection.
            $table->string('type')->default('collection')->after('name');
            $table->string('target_url', 2048)->nullable()->after('type');
        });

        Schema::table('monitors', function (Blueprint $table) {
            // Drift monitors have no collection.
            $table->unsignedBigInteger('collection_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn(['type', 'target_url']);
        });
    }
};
