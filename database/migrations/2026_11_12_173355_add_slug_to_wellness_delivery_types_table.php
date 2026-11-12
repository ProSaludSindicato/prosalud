<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Identificador legible de la campaña para su enlace público de inscripción (ej. /entregas-bienestar/fiesta-fin-de-ano).
     */
    public function up(): void
    {
        Schema::table('wellness_delivery_types', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->unique()->after('nombre');
        });

        $usados = [];
        DB::table('wellness_delivery_types')->orderBy('id')->get(['id', 'nombre'])->each(function (object $type) use (&$usados) {
            $base = Str::limit(Str::slug($type->nombre), 100, '') ?: 'campana';
            $slug = $base;
            $ordinal = 1;
            while (isset($usados[$slug])) {
                $ordinal++;
                $slug = $base.'-'.$ordinal;
            }
            $usados[$slug] = true;

            DB::table('wellness_delivery_types')->where('id', $type->id)->update(['slug' => $slug]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_delivery_types', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
