<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            // Who opened it. Never published — the public page says what is
            // happening, not who is handling it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 20);
            // The timeline, as appended entries: [{at, status, body}]. A status
            // page that only shows the latest line is much less use than one
            // that shows how the picture changed.
            $table->json('updates')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status_page_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_incidents');
    }
};
