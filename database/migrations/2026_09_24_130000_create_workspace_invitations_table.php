<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An organisation is the sharing boundary (see User::workspaceUserIds),
        // so it needs someone who may remove members. Existing organisations
        // have none; Organisation::ownerId() falls back to the first member.
        Schema::table('organisations', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('slug')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            // Hashed, never stored raw: the link in the email is the only copy,
            // exactly as API keys and share tokens are handled.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organisation_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
