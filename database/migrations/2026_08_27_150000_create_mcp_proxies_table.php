<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_proxies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Agents call /mcp-proxy/{token}; the token is the credential.
            $table->string('token', 64)->unique();
            $table->string('upstream_url', 2048);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('mcp_proxy_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_proxy_id')->constrained()->cascadeOnDelete();
            // JSON-RPC method, or "GET stream"/"DELETE session" for the
            // non-POST verbs of the Streamable HTTP transport.
            $table->string('method');
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            // Set when the injection scanner found something in the response.
            $table->boolean('flagged')->default(false);
            $table->string('flag_summary')->nullable();
            $table->timestamps();

            $table->index(['mcp_proxy_id', 'created_at']);
            $table->index(['mcp_proxy_id', 'flagged']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_proxy_exchanges');
        Schema::dropIfExists('mcp_proxies');
    }
};
