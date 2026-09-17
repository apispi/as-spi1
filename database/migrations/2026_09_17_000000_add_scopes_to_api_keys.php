<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // Null / empty = full access (all abilities), preserving keys issued
            // before scopes existed. A populated list restricts the key.
            $table->json('scopes')->nullable()->after('last_four');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('scopes');
        });
    }
};
