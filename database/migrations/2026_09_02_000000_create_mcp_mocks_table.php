<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_mocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token', 64)->unique();        // public /mcp-mock/{token}
            $table->string('server_name')->default('Spi Mock');
            $table->string('server_version')->default('1.0.0');
            $table->boolean('is_enabled')->default(true);
            // [{name, description, inputSchema, response}] — response is the
            // canned tools/call result the mock returns for that tool.
            $table->json('tools')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_mocks');
    }
};
