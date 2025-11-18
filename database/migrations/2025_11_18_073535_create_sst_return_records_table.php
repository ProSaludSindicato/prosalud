<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sst_return_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('affiliate_id');
            $table->string('affiliate_document_type', 10);
            $table->string('affiliate_document_number');
            $table->string('affiliate_first_name')->nullable();
            $table->string('affiliate_last_name')->nullable();
            $table->string('affiliate_hospital')->nullable();
            $table->string('affiliate_role')->nullable();

            $table->foreignId('received_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('received_by_name')->nullable();

            $table->timestamp('returned_at')->useCurrent();

            $table->string('signature_path')->nullable();
            $table->string('signature_mime_type', 100)->nullable();

            $table->string('signed_document_type', 10);
            $table->string('signed_document_number');
            $table->string('reason', 50)->default('replacement'); // retirement, replacement, other
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['affiliate_id']);
            $table->index(['affiliate_document_type', 'affiliate_document_number'], 'return_records_document_idx');
            $table->index(['received_by_user_id']);
            $table->index(['returned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sst_return_records');
    }
};
