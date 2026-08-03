<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->foreignId('generated_by_user_id')
                ->nullable()
                ->after('sede')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::statement("ALTER TABLE convenio_email_tracking MODIFY COLUMN estado ENUM('pendiente', 'enviado', 'fallido', 'verificacion') NOT NULL DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generated_by_user_id');
        });

        DB::statement("ALTER TABLE convenio_email_tracking MODIFY COLUMN estado ENUM('pendiente', 'enviado', 'fallido') NOT NULL DEFAULT 'pendiente'");
    }
};
