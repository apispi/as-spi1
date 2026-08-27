<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            // Inferred JSON-Schema baseline for this request's response. When
            // set, every run checks the live response against it and reports
            // drift. See App\Services\Contracts.
            $table->json('contract')->nullable()->after('assertions');
        });
    }

    public function down(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            $table->dropColumn('contract');
        });
    }
};
