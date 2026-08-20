<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Keep running after a step fails, rather than stopping the run.
            $table->boolean('continue_on_failure')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('collection_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            // Deleting the saved request removes the step: a step with nothing
            // to send is not a step.
            $table->foreignId('saved_request_id')->constrained('saved_requests')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // [{ name, path }] — values pulled out of this step's response and
            // made available as {{name}} to later steps.
            $table->json('extract')->nullable();
            $table->timestamps();

            $table->index(['collection_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_steps');
        Schema::dropIfExists('collections');
    }
};
