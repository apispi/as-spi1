<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // The connector this report is about. Nullable + denormalised name
            // so a report survives its connector being deleted or re-slugged.
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('connector_slug')->nullable();
            $table->string('connector_name')->nullable();
            // agent_loop | conformance | security
            $table->string('type');
            // One-line headline: a grade, a risk level, or a stop reason.
            $table->string('summary')->nullable();
            // The full inspection result, rendered verbatim by the report view.
            $table->json('data');
            // Set when the owner shares the report; nullable + unique so an
            // unshared report has no public URL and tokens don't collide.
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['user_id', 'type', 'created_at']);
            $table->index(['catalog_item_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_reports');
    }
};
