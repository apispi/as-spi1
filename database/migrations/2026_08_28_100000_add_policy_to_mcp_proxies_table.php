<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_proxies', function (Blueprint $table) {
            // Ordered firewall rules applied inline by the relay:
            // [{action, direction, tool, pattern, on_injection}]
            $table->json('policy')->nullable()->after('upstream_url');
        });

        Schema::table('mcp_proxy_exchanges', function (Blueprint $table) {
            // What the firewall did to this exchange, if anything:
            // {action, note, redactions, rule}
            $table->json('enforcement')->nullable()->after('flag_summary');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_proxy_exchanges', fn (Blueprint $t) => $t->dropColumn('enforcement'));
        Schema::table('mcp_proxies', fn (Blueprint $t) => $t->dropColumn('policy'));
    }
};
