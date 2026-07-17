<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Email-first registration: the account exists (email only) before
            // the user sets their name and password via a verification link.
            $table->string('name')->nullable()->change();
            $table->string('registration_token')->nullable()->after('avatar');
            $table->timestamp('registration_token_expires_at')->nullable()->after('registration_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['registration_token', 'registration_token_expires_at']);
        });
    }
};
