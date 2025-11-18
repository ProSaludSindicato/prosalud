<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class RemoveLegacyPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:remove-legacy {--force : Eliminar sin confirmación}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elimina permisos legacy que ya no están definidos en el seeder actual';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Buscando permisos legacy...');

        // Limpiar caché de permisos
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Leer el archivo del seeder para extraer los permisos definidos
        $seederFile = file_get_contents(base_path('database/seeders/RolePermissionSeeder.php'));

        // Parsear los permisos del array $permissions en el seeder
        $definedPermissions = $this->extractPermissionsFromSeeder($seederFile);

        if (empty($definedPermissions)) {
            $this->error('No se pudieron extraer los permisos del seeder.');

            return Command::FAILURE;
        }

        $this->info('✓ Permisos definidos en el seeder: ' . count($definedPermissions));

        // Obtener todos los permisos de la base de datos
        $dbPermissions = Permission::where('guard_name', 'web')
            ->pluck('name')
            ->toArray();

        $this->info('✓ Permisos en la base de datos: ' . count($dbPermissions));

        // Encontrar permisos legacy (están en BD pero no en el seeder)
        $legacyPermissions = array_diff($dbPermissions, $definedPermissions);

        if (empty($legacyPermissions)) {
            $this->info('✓ No se encontraron permisos legacy. Todo está actualizado.');

            return Command::SUCCESS;
        }

        $this->warn("\n⚠ Se encontraron " . count($legacyPermissions) . ' permisos legacy:');
        $this->table(
            ['Permisos legacy a eliminar'],
            array_map(fn ($perm) => [$perm], $legacyPermissions)
        );

        // Verificar relaciones antes de eliminar
        $this->info("\nVerificando relaciones...");
        $permissionsWithRelations = [];

        foreach ($legacyPermissions as $permissionName) {
            $permission = Permission::where('name', $permissionName)
                ->where('guard_name', 'web')
                ->first();

            if ($permission) {
                $roleCount = DB::table('role_has_permissions')
                    ->where('permission_id', $permission->id)
                    ->count();

                $modelCount = DB::table('model_has_permissions')
                    ->where('permission_id', $permission->id)
                    ->count();

                if ($roleCount > 0 || $modelCount > 0) {
                    $permissionsWithRelations[$permissionName] = [
                        'roles' => $roleCount,
                        'models' => $modelCount,
                    ];
                }
            }
        }

        if (!empty($permissionsWithRelations)) {
            $this->warn("\n⚠ Los siguientes permisos tienen relaciones activas:");
            $rows = [];
            foreach ($permissionsWithRelations as $perm => $relations) {
                $rows[] = [
                    $perm,
                    $relations['roles'] . ' roles',
                    $relations['models'] . ' usuarios/modelos',
                ];
            }
            $this->table(
                ['Permiso', 'Asignado a', 'Asignado directamente a'],
                $rows
            );
            $this->warn('Estas relaciones se eliminarán automáticamente (cascade delete).');
        }

        // Confirmar eliminación
        if (!$this->option('force')) {
            if (!$this->confirm('¿Deseas eliminar estos permisos legacy?', true)) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        }

        // Eliminar permisos legacy
        $this->info("\nEliminando permisos legacy...");
        $deletedCount = 0;

        foreach ($legacyPermissions as $permissionName) {
            $permission = Permission::where('name', $permissionName)
                ->where('guard_name', 'web')
                ->first();

            if ($permission) {
                $permission->delete();
                ++$deletedCount;
                $this->line("  ✓ Eliminado: {$permissionName}");
            }
        }

        // Limpiar caché nuevamente
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->info("\n✓ Proceso completado. Se eliminaron {$deletedCount} permisos legacy.");

        return Command::SUCCESS;
    }

    /**
     * Extrae los nombres de permisos del array $permissions en el seeder.
     */
    private function extractPermissionsFromSeeder(string $seederContent): array
    {
        $permissions = [];

        // Buscar el array $permissions (hasta encontrar el siguiente array o el cierre)
        // Necesitamos capturar desde $permissions = [ hasta ]; pero antes de $_permissions
        if (preg_match('/\$permissions\s*=\s*\[(.*?)\];/s', $seederContent, $matches)) {
            $arrayContent = $matches[1];

            // Extraer las claves del array (nombres de permisos)
            // Formato: 'permission.name' => 'Description',
            // Puede usar comillas simples o dobles
            preg_match_all("/(?:'|\")([^'\"]+)['\"]\s*=>/", $arrayContent, $permissionMatches);

            if (!empty($permissionMatches[1])) {
                $permissions = array_unique($permissionMatches[1]);
            }
        }

        return $permissions;
    }
}
