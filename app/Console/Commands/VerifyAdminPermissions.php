<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class VerifyAdminPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:verify-admin {--fix : Corregir automáticamente los permisos del admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica y corrige los permisos del usuario administrador';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Verificando permisos del usuario administrador...');

        // Limpiar caché de permisos
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->info('Caché de permisos limpiado.');

        // Verificar que el rol admin existe
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        if (!$adminRole) {
            $this->error('El rol "admin" no existe en la base de datos.');
            $this->warn('Ejecuta: php artisan db:seed --class=RolePermissionSeeder');
            return Command::FAILURE;
        }

        $this->info("✓ Rol 'admin' encontrado (ID: {$adminRole->id})");

        // Verificar permisos del rol admin
        $rolePermissions = $adminRole->permissions->pluck('name')->toArray();
        $this->info("✓ El rol admin tiene " . count($rolePermissions) . " permisos asignados");

        // Buscar usuario admin
        $adminUser = User::where('email', 'admin@prosalud.com')->first();

        if (!$adminUser) {
            $this->error('Usuario admin@prosalud.com no encontrado.');
            $this->warn('Ejecuta: php artisan db:seed --class=UserSeeder');
            return Command::FAILURE;
        }

        $this->info("✓ Usuario admin encontrado (ID: {$adminUser->id}, Email: {$adminUser->email})");

        // Verificar si el usuario tiene el rol admin
        $hasAdminRole = $adminUser->hasRole('admin');
        $userRoles = $adminUser->getRoleNames()->toArray();
        $userPermissions = $adminUser->getAllPermissions()->pluck('name')->toArray();

        $this->table(
            ['Propiedad', 'Valor'],
            [
                ['Tiene rol admin', $hasAdminRole ? '✓ Sí' : '✗ No'],
                ['Roles del usuario', implode(', ', $userRoles ?: ['Ninguno'])],
                ['Permisos del usuario', count($userPermissions) . ' permisos'],
            ]
        );

        if (!$hasAdminRole) {
            $this->warn('El usuario admin NO tiene el rol "admin" asignado.');

            if ($this->option('fix')) {
                $adminUser->assignRole('admin');
                $this->info('✓ Rol "admin" asignado al usuario.');
                Log::info('[PERMISSIONS FIX] Rol admin asignado al usuario', [
                    'user_id' => $adminUser->id,
                    'user_email' => $adminUser->email,
                ]);
            } else {
                $this->warn('Ejecuta con --fix para corregir automáticamente.');
                return Command::FAILURE;
            }
        }

        // Verificar que los permisos estén sincronizados
        $allPermissions = Permission::where('guard_name', 'web')->pluck('name')->toArray();
        $missingPermissions = array_diff($allPermissions, $userPermissions);

        if (!empty($missingPermissions)) {
            $this->warn('El usuario admin tiene permisos faltantes:');
            $this->line(implode(', ', $missingPermissions));

            if ($this->option('fix')) {
                // Sincronizar todos los permisos del rol admin
                $adminRole->syncPermissions(Permission::all());
                $this->info('✓ Permisos del rol admin sincronizados.');
                Log::info('[PERMISSIONS FIX] Permisos del rol admin sincronizados', [
                    'permissions_count' => Permission::count(),
                ]);
            } else {
                $this->warn('Ejecuta con --fix para sincronizar los permisos.');
            }
        }

        // Limpiar caché nuevamente después de los cambios
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Verificar nuevamente después de los cambios
        $adminUser->refresh();
        $adminUser->load('roles', 'permissions', 'roles.permissions');
        $finalPermissions = $adminUser->getAllPermissions()->pluck('name')->toArray();

        $this->info("\nEstado final:");
        $this->table(
            ['Propiedad', 'Valor'],
            [
                ['Tiene rol admin', $adminUser->hasRole('admin') ? '✓ Sí' : '✗ No'],
                ['Total de permisos', count($finalPermissions)],
                ['Total de permisos esperados', count($allPermissions)],
            ]
        );

        if (count($finalPermissions) === count($allPermissions)) {
            $this->info('✓ Todos los permisos están correctamente asignados.');
            Log::info('[PERMISSIONS VERIFY] Permisos del admin verificados correctamente', [
                'user_id' => $adminUser->id,
                'permissions_count' => count($finalPermissions),
            ]);
            return Command::SUCCESS;
        } else {
            $this->warn('⚠ Aún hay diferencias en los permisos.');
            return Command::FAILURE;
        }
    }
}


