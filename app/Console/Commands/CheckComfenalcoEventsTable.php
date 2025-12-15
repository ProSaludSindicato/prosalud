<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckComfenalcoEventsTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'comfenalco:check-table';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica la estructura de la tabla comfenalco_events para debugging';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Verificando estructura de la tabla comfenalco_events...');
        $this->newLine();

        // Check if table exists
        if (!Schema::hasTable('comfenalco_events')) {
            $this->error('❌ La tabla comfenalco_events NO existe en la base de datos.');
            $this->warn('   Ejecuta las migraciones: php artisan migrate');
            return 1;
        }

        $this->info('✅ La tabla comfenalco_events existe.');
        $this->newLine();

        // Get table structure
        try {
            $columns = DB::select('DESCRIBE comfenalco_events');
            
            $this->info('📋 Columnas de la tabla:');
            $this->newLine();
            
            $tableData = [];
            foreach ($columns as $column) {
                $columnData = (array) $column;
                $tableData[] = [
                    'Campo' => $columnData['Field'],
                    'Tipo' => $columnData['Type'],
                    'Null' => $columnData['Null'],
                    'Key' => $columnData['Key'],
                    'Default' => $columnData['Default'] ?? 'NULL',
                    'Extra' => $columnData['Extra'] ?? '',
                ];
            }
            
            $this->table(
                ['Campo', 'Tipo', 'Null', 'Key', 'Default', 'Extra'],
                $tableData
            );
            $this->newLine();

            // Check for id column
            $hasIdColumn = false;
            $idIsPrimary = false;
            
            foreach ($columns as $column) {
                $columnData = (array) $column;
                if ($columnData['Field'] === 'id') {
                    $hasIdColumn = true;
                    if ($columnData['Key'] === 'PRI') {
                        $idIsPrimary = true;
                    }
                    break;
                }
            }

            // Check primary keys
            $primaryKeys = DB::select("SHOW KEYS FROM comfenalco_events WHERE Key_name = 'PRIMARY'");
            
            $this->info('🔑 Claves primarias:');
            if (empty($primaryKeys)) {
                $this->warn('   ⚠️  No hay clave primaria definida en la tabla.');
            } else {
                foreach ($primaryKeys as $pk) {
                    $pkData = (array) $pk;
                    $this->line("   • {$pkData['Column_name']} (Secuencia: {$pkData['Seq_in_index']})");
                }
            }
            $this->newLine();

            // Summary
            $this->info('📊 Resumen:');
            if ($hasIdColumn) {
                $this->info("   ✅ Columna 'id' existe");
                if ($idIsPrimary) {
                    $this->info("   ✅ Columna 'id' es clave primaria");
                } else {
                    $this->error("   ❌ Columna 'id' NO es clave primaria");
                }
            } else {
                $this->error("   ❌ Columna 'id' NO existe");
                $this->warn('   💡 Ejecuta la migración: php artisan migrate');
            }
            $this->newLine();

            // Get row count
            try {
                $rowCount = DB::table('comfenalco_events')->count();
                $this->info("📈 Total de registros: {$rowCount}");
            } catch (\Exception $e) {
                $this->warn("   ⚠️  No se pudo contar los registros: {$e->getMessage()}");
            }
            $this->newLine();

            // Check indexes
            $indexes = DB::select("SHOW INDEXES FROM comfenalco_events");
            if (!empty($indexes)) {
                $this->info('🔍 Índices:');
                $indexGroups = [];
                foreach ($indexes as $index) {
                    $indexData = (array) $index;
                    $keyName = $indexData['Key_name'];
                    if (!isset($indexGroups[$keyName])) {
                        $indexGroups[$keyName] = [];
                    }
                    $indexGroups[$keyName][] = $indexData['Column_name'];
                }
                
                foreach ($indexGroups as $keyName => $columns) {
                    $isPrimary = $keyName === 'PRIMARY' ? ' (PRIMARY)' : '';
                    $this->line("   • {$keyName}{$isPrimary}: " . implode(', ', $columns));
                }
                $this->newLine();
            }

            // Database connection info
            $this->info('🔌 Información de conexión:');
            $connection = DB::connection();
            $config = $connection->getConfig();
            $this->line("   • Base de datos: {$config['database']}");
            $this->line("   • Host: {$config['host']}");
            $this->line("   • Driver: {$connection->getDriverName()}");
            $this->newLine();

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Error al verificar la tabla:');
            $this->error("   {$e->getMessage()}");
            $this->newLine();
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
