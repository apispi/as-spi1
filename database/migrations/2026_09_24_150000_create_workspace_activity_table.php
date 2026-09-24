<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_activity', function (Blueprint $table) {
            $table->id();
            // The actor. Workspace scoping is derived from this at read time,
            // the same way every other shared resource is scoped, so a row
            // stays correct when somebody changes workspace.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            // Denormalised: the log has to survive the delete it records, so it
            // cannot look the name up again later.
            $table->string('subject_name')->nullable();
            $table->string('action', 20);
            $table->string('summary')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_activity');
    }
};
