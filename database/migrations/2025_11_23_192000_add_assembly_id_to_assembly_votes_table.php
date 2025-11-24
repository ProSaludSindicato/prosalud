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
        Schema::table('assembly_votes', function (Blueprint $table) {
            // Agregar assembly_id como nullable
            // La creación de la asamblea inicial y asociación de datos se hace mediante comando
            $table->unsignedBigInteger('assembly_id')->nullable()->after('question_id');
            
            $table->foreign('assembly_id')
                ->references('id')
                ->on('assemblies')
                ->onDelete('cascade');
            
            $table->index('assembly_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assembly_votes', function (Blueprint $table) {
            $table->dropForeign(['assembly_id']);
            $table->dropIndex(['assembly_id']);
            $table->dropColumn('assembly_id');
        });
    }
};
