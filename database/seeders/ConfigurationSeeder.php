<?php

namespace Database\Seeders;

use App\Models\Configuration;
use Illuminate\Database\Seeder;

class ConfigurationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Configuración para modo ingreso masivo de encuestas
        Configuration::set(
            'survey.allow_bulk_entry_mode',
            false,
            'boolean',
            'Permite modo ingreso masivo sin autenticación para encuestas sociodemográficas. Cuando está activo, no se requiere autenticación previa y todos los campos deben ser diligenciados manualmente.'
        );
    }
}
