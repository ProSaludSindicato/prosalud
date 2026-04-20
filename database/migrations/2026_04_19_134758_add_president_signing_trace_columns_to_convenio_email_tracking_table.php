<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->unsignedTinyInteger('president_sign_attempts')->default(0)->after('motivo_rechazo');
            $table->text('president_sign_last_error')->nullable()->after('president_sign_attempts');
            $table->string('president_sign_detection_method', 50)->nullable()->after('president_sign_last_error');
            $table->timestamp('president_sign_queued_at')->nullable()->after('president_sign_detection_method');
            $table->unsignedInteger('president_sign_duration_ms')->nullable()->after('president_sign_queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropColumn([
                'president_sign_attempts',
                'president_sign_last_error',
                'president_sign_detection_method',
                'president_sign_queued_at',
                'president_sign_duration_ms',
            ]);
        });
    }
};
