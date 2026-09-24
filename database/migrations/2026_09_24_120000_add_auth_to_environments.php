<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An environment-level auth config that requests inherit by default, so an
     * OAuth client is configured once rather than on every saved request.
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->json('auth')->nullable()->after('variables');
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('auth');
        });
    }
};
