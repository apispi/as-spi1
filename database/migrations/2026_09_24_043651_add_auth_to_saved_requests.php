<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A saved request's authentication config: the scheme plus its fields,
     * whose values are normally {{variables}} so the credential itself stays
     * in a secret environment variable rather than in this row.
     */
    public function up(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            $table->json('auth')->nullable()->after('headers');
        });
    }

    public function down(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            $table->dropColumn('auth');
        });
    }
};
