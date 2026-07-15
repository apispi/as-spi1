<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('protocol')->default('rest');
            $table->string('method');
            $table->text('url');
            // Request bodies/params are stored for replay; headers are
            // deliberately NOT stored since they routinely carry credentials.
            $table->json('params')->nullable();
            $table->longText('body')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('time_ms')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_histories');
    }
};
