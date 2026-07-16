<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Only the SHA-256 hash of the key is stored; the plaintext is
            // shown once at generation time and never persisted.
            $table->string('api_token', 64)->nullable()->unique()->after('preferences');
            $table->string('api_token_last_four', 4)->nullable()->after('api_token');
            $table->timestamp('api_token_created_at')->nullable()->after('api_token_last_four');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_token', 'api_token_last_four', 'api_token_created_at']);
        });
    }
};
