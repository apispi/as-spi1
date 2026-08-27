<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The capture URL is /hook/{token}; the token is the only secret.
            $table->string('token', 64)->unique();
            // Dead-man's switch: expect a hit at least every N minutes, alert
            // on silence. Null means capture-only, no expectation.
            $table->unsignedSmallInteger('expect_interval_minutes')->nullable();
            $table->boolean('alerts_enabled')->default(true);
            $table->timestamp('last_received_at')->nullable();
            // unknown | receiving | silent
            $table->string('last_status')->default('unknown');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('webhook_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->json('headers')->nullable();
            $table->json('query')->nullable();
            // Bounded at capture time; see WebhookCaptureController::MAX_BODY.
            $table->text('body')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_captures');
        Schema::dropIfExists('webhook_endpoints');
    }
};
