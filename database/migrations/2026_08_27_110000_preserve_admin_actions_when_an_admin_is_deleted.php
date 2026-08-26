<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * admin_id cascaded, so hard-deleting an admin erased every action they
     * had ever taken — destroying the audit trail exactly when it matters
     * most. The log now outlives the account: the id goes null and the email
     * is kept as a snapshot, mirroring how target_user_id/target_email
     * already work for the other side of the record.
     */
    public function up(): void
    {
        Schema::table('admin_actions', function (Blueprint $table) {
            $table->string('admin_email')->nullable()->after('admin_id');
        });

        // Backfill from the accounts that still exist.
        DB::statement('
            update admin_actions
            set admin_email = (select email from users where users.id = admin_actions.admin_id)
            where admin_email is null
        ');

        Schema::table('admin_actions', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->unsignedBigInteger('admin_id')->nullable()->change();
            $table->foreign('admin_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admin_actions', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_email');
        });
    }
};
