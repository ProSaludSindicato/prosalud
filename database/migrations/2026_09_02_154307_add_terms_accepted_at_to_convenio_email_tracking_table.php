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
        if (Schema::hasColumn('convenio_email_tracking', 'terms_accepted_at')) {
            return;
        }

        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('signing_audit_log');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('convenio_email_tracking', 'terms_accepted_at')) {
            return;
        }

        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropColumn('terms_accepted_at');
        });
    }
};
