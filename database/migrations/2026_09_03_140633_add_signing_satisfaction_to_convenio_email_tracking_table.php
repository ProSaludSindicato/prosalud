<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->unsignedTinyInteger('signing_satisfaction_score')->nullable()->after('terms_accepted_at');
            $table->timestamp('signing_satisfaction_rated_at')->nullable()->after('signing_satisfaction_score');
        });
    }

    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropColumn([
                'signing_satisfaction_score',
                'signing_satisfaction_rated_at',
            ]);
        });
    }
};
