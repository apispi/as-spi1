<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One table for all five catalog entity types, distinguished by
        // `type`, since they share the same shape. "Active" is workspace-wide
        // (is_active) rather than per-user.
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // agent|skill|connector|tool|prompt
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('version')->nullable();
            $table->string('provider')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['type', 'slug']);
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
