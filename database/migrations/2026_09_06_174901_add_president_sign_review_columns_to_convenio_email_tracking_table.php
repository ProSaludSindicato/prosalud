<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            if (! Schema::hasColumn('convenio_email_tracking', 'president_sign_batch_id')) {
                $table->unsignedBigInteger('president_sign_batch_id')->nullable()->after('president_sign_last_error');
            }

            if (! Schema::hasColumn('convenio_email_tracking', 'president_sign_requested_by_user_id')) {
                $table->unsignedBigInteger('president_sign_requested_by_user_id')->nullable()->after('president_sign_batch_id');
            }

            if (! Schema::hasColumn('convenio_email_tracking', 'completed_by_user_id')) {
                $table->unsignedBigInteger('completed_by_user_id')->nullable()->after('president_sign_requested_by_user_id');
            }

            if (! Schema::hasColumn('convenio_email_tracking', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('completed_by_user_id');
            }

            if (! Schema::hasColumn('convenio_email_tracking', 'completed_email_sent_at')) {
                $table->timestamp('completed_email_sent_at')->nullable()->after('completed_at');
            }

            if (! Schema::hasColumn('convenio_email_tracking', 'completed_email_last_error')) {
                $table->text('completed_email_last_error')->nullable()->after('completed_email_sent_at');
            }
        });

        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            if (! $this->foreignKeyExists('convenio_email_tracking', 'cet_president_sign_batch_fk')) {
                $table->foreign('president_sign_batch_id', 'cet_president_sign_batch_fk')
                    ->references('id')
                    ->on('convenio_president_sign_batches')
                    ->nullOnDelete();
            }

            if (! $this->foreignKeyExists('convenio_email_tracking', 'cet_president_sign_req_user_fk')) {
                $table->foreign('president_sign_requested_by_user_id', 'cet_president_sign_req_user_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (! $this->foreignKeyExists('convenio_email_tracking', 'cet_completed_by_user_fk')) {
                $table->foreign('completed_by_user_id', 'cet_completed_by_user_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            if ($this->foreignKeyExists('convenio_email_tracking', 'cet_president_sign_batch_fk')) {
                $table->dropForeign('cet_president_sign_batch_fk');
            }

            if ($this->foreignKeyExists('convenio_email_tracking', 'cet_president_sign_req_user_fk')) {
                $table->dropForeign('cet_president_sign_req_user_fk');
            }

            if ($this->foreignKeyExists('convenio_email_tracking', 'cet_completed_by_user_fk')) {
                $table->dropForeign('cet_completed_by_user_fk');
            }

            $columns = [
                'president_sign_batch_id',
                'president_sign_requested_by_user_id',
                'completed_by_user_id',
                'completed_at',
                'completed_email_sent_at',
                'completed_email_last_error',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('convenio_email_tracking', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$database, $table, $name, 'FOREIGN KEY'],
        );

        return $result !== [];
    }
};
