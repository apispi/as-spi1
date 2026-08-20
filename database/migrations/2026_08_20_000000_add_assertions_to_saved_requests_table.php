<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            // [{ path, operator, expected, description }] — evaluated against
            // the response by App\Services\Assertions\AssertionEvaluator.
            $table->json('assertions')->nullable()->after('params');
        });
    }

    public function down(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            $table->dropColumn('assertions');
        });
    }
};
