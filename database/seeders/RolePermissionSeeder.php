<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\{Permission, Role};

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Requests
            'requests.view' => 'Ver listado y detalle de solicitudes de los afiliados',
            'requests.respond' => 'Responder solicitudes de los afiliados y actualizar su estado',

            // Votes
            'votes.statistics.view' => 'Ver estadísticas generales de votaciones (incluye estadísticas por hospital)',
            'votes.audit.view' => 'Ver auditoría y trazabilidad de votaciones y gestionar cambios de candidato',

            // Assembly voting
            'assembly.questions.manage' => 'Gestionar preguntas y estados de votación de la asamblea',
            'assembly.quorum.manage' => 'Gestionar y verificar el quórum de la asamblea',

            // Users
            'users.view' => 'Ver listado y detalle de usuarios del sistema',
            'users.create' => 'Crear nuevos usuarios del sistema',
            'users.edit' => 'Editar información de los usuarios del sistema',
            'users.change_status' => 'Activar o inactivar usuarios del sistema',

            // Wellness Events
            'wellness_events.view' => 'Ver eventos de bienestar',
            'wellness_events.create' => 'Crear nuevos eventos de bienestar',
            'wellness_events.edit' => 'Editar eventos de bienestar (incluye cambiar visibilidad e imágenes)',

            // Wellness Requests / Activities
            'wellness_requests.view' => 'Ver solicitudes de bienestar',
            'wellness_requests.create' => 'Crear solicitudes de bienestar',
            'wellness_requests.edit' => 'Editar solicitudes de bienestar',
            'wellness_requests.update_status' => 'Actualizar el estado de las solicitudes de bienestar',
            'wellness_activity.publish' => 'Revisar y publicar actividades de bienestar en la galería',

            // Comfenalco Events
            'comfenalco_events.view' => 'Ver eventos de Comfenalco',
            'comfenalco_events.create' => 'Crear nuevos eventos de Comfenalco',
            'comfenalco_events.edit' => 'Editar eventos de Comfenalco (incluye cambiar visibilidad)',
            'comfenalco_events.delete' => 'Eliminar eventos de Comfenalco',

            // Chatbot conversations
            'chatbot.manage' => 'Gestionar conversaciones del chatbot y feedback',

            // Files uploads / info
            'activos_files.manage' => 'Subir y gestionar archivos de activos',
            'afiliados_files.manage' => 'Subir y gestionar archivos de afiliados',
            'incapacidades_files.manage' => 'Subir y gestionar archivos de incapacidades',
            'liquidaciones_files.manage' => 'Subir y gestionar archivos de liquidaciones',
            'delegados_files.manage' => 'Subir y gestionar archivos de delegados',
            'compensaciones_files.manage' => 'Subir y gestionar archivos de compensaciones',

            // Roles / permissions
            'roles.manage' => 'Gestionar roles y permisos del sistema (crear, editar, asignar permisos)',

            // Dotación / EPP
            'dotacion.view' => 'Ver información de dotación y EPP (afiliados, inventario, entregas realizadas)',
            'dotacion.deliveries.create' => 'Registrar entregas de dotación y EPP a los afiliados',

            // Inventory
            'inventory.view_dashboard' => 'Ver dashboard del inventario',
            'inventory.categories.view' => 'Ver categorías del inventario',
            'inventory.categories.manage' => 'Gestionar categorías y subcategorías del inventario',
            'inventory.products.view' => 'Ver productos del inventario',
            'inventory.products.manage' => 'Gestionar productos del inventario (crear y editar)',
            'inventory.entries.view' => 'Ver entradas de inventario',
            'inventory.entries.manage' => 'Gestionar entradas de inventario (crear, ver detalle)',
            'inventory.locations.view' => 'Ver ubicaciones del inventario',
            'inventory.stock_movements.view' => 'Ver movimientos de stock del inventario',

            // Hospital requests
            'hospital_requests.view' => 'Ver solicitudes de inventario de hospitales',
            'hospital_requests.create' => 'Crear solicitudes de inventario de hospitales',
            'hospital_requests.update_status' => 'Cambiar el estado de las solicitudes de inventario de hospitales',
        ];

        $_permissions = [
            // Requests (nuevo formato)
            'requests.view' => 'Ver listado de solicitudes de los afiliados', // SI
            'requests.create' => 'Crear nuevas solicitudes de los afiliados', // NO
            'requests.update_status' => 'Cambiar el estado de las solicitudes de los afiliados',  // NO
            'requests.respond' => 'Responder a las solicitudes de los afiliados', // SI
            'requests.download_files' => 'Descargar archivos adjuntos de las solicitudes',  // NO

            // Request Forms (formato legacy)
            /*'request_forms.view' => 'Ver listado de formularios de solicitudes',
            'request_forms.create' => 'Crear nuevos formularios de solicitudes',
            'request_forms.edit' => 'Editar formularios de solicitudes',
            'request_forms.delete' => 'Eliminar formularios de solicitudes',*/

            // Votes
            'votes.statistics.view' => 'Ver estadísticas generales de votaciones',  // SI
            'votes.hospital_statistics.view' => 'Ver estadísticas de votaciones por hospital', // NO
            'votes.audit.view' => 'Ver auditoría y trazabilidad de votaciones',  // SI
            'votes.manage' => 'Gestionar votaciones y cambiar candidatos', // NO
            'assembly.questions.manage' => 'Gestionar preguntas y estados de votación de la asamblea',
            'assembly.quorum.manage' => 'Gestionar y verificar el quórum de la asamblea',

            // Users (nuevo formato)
            'users.manage' => 'Gestionar usuarios del sistema (crear, editar, activar/desactivar)', // NO

            // Users (formato legacy)
            'users.view' => 'Ver listado de usuarios del sistema', // SI
            'users.create' => 'Crear nuevos usuarios del sistema', // SI
            'users.edit' => 'Editar usuarios del sistema', // SI
            'users.delete' => 'Eliminar usuarios del sistema',  // NO

            // Wellness Events (nuevo formato)
            'wellness_events.manage' => 'Gestionar eventos de bienestar (crear, editar, cambiar visibilidad, imágenes)',    // NO

            // Wellness Events (formato legacy)
            'wellness_events.view' => 'Ver eventos de bienestar', // SI
            'wellness_events.create' => 'Crear nuevos eventos de bienestar', // SI
            'wellness_events.edit' => 'Editar eventos de bienestar', // SI
            'wellness_events.delete' => 'Eliminar eventos de bienestar',

            // Wellness Requests / Activities
            'wellness_requests.view' => 'Ver solicitudes de bienestar', // SI
            'wellness_requests.manage' => 'Gestionar solicitudes de bienestar (crear, editar)',
            'wellness_activity.manage' => 'Gestionar actividades realizadas y publicar en galería',

            // Comfenalco Events (nuevo formato)
            'comfenalco_events.manage' => 'Gestionar eventos de Comfenalco (crear, editar, cambiar visibilidad)', // NO

            // Comfenalco Events (formato legacy)
            'comfenalco_events.view' => 'Ver eventos de Comfenalco', // SI
            'comfenalco_events.create' => 'Crear nuevos eventos de Comfenalco', // SI
            'comfenalco_events.edit' => 'Editar eventos de Comfenalco', // SI
            'comfenalco_events.delete' => 'Eliminar eventos de Comfenalco', // SI

            // Chatbot conversations
            'chatbot.manage' => 'Gestionar conversaciones del chatbot y feedback', // SI

            // Files uploads / info
            'activos_files.view' => 'Ver información de archivos de activos', // NO
            'activos_files.manage' => 'Subir y gestionar archivos de activos', // SI
            'afiliados_files.manage' => 'Subir y gestionar archivos de afiliados', // SI
            'incapacidades_files.manage' => 'Subir y gestionar archivos de incapacidades', // SI
            'liquidaciones_files.manage' => 'Subir y gestionar archivos de liquidaciones', // SI
            'delegados_files.manage' => 'Subir y gestionar archivos de delegados', // SI
            'compensaciones_files.manage' => 'Subir y gestionar archivos de compensaciones', // SI

            // Roles / permissions (nuevo formato)
            'roles.manage' => 'Gestionar roles del sistema (crear, editar, asignar permisos)', // SI
            'permissions.view' => 'Ver listado de permisos disponibles', // NO
            'permissions.manage' => 'Gestionar permisos del sistema (editar nombres)', // NO

            // Roles / permissions (formato legacy)
            'roles.view' => 'Ver listado de roles del sistema', // NO
            'roles.create' => 'Crear nuevos roles del sistema', // NO
            'roles.edit' => 'Editar roles del sistema', // NO
            'roles.delete' => 'Eliminar roles del sistema', // NO
            'permissions.edit' => 'Editar permisos del sistema', // NO

            // Dotación / EPP
            'dotacion.manage' => 'Gestionar dotación y EPP (afiliados, inventario, entregas)', // SEPARACION

            // Inventory
            'inventory.view_dashboard' => 'Ver dashboard del inventario', // SI
            'inventory.categories.view' => 'Ver categorías del inventario', // SI
            'inventory.categories.manage' => 'Gestionar categorías y subcategorías del inventario', // SI
            'inventory.products.view' => 'Ver productos del inventario', // SI
            'inventory.products.manage' => 'Gestionar productos del inventario (crear, editar, eliminar)', // SI
            // 'inventory.colors.view' => 'Ver catálogo de colores del inventario', // NO
            'inventory.entries.view' => 'Ver entradas de inventario', // SI
            'inventory.entries.manage' => 'Gestionar entradas de inventario (crear, ver detalle)', // SI
            'inventory.locations.view' => 'Ver ubicaciones del inventario', // SI
            'inventory.stock_movements.view' => 'Ver movimientos de stock del inventario', // SI

            // Hospital requests
            'hospital_requests.view' => 'Ver solicitudes de inventario de hospitales', // SI
            'hospital_requests.manage' => 'Gestionar solicitudes de inventario de hospitales (crear, cambiar estado)',
        ];

        foreach ($permissions as $permission => $description) {
            Permission::updateOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['description' => $description]
            );
        }

        // Asegurar que todos los permisos existentes tengan descripción
        $allPermissions = Permission::where('guard_name', 'web')->get();
        foreach ($allPermissions as $permission) {
            if (empty($permission->description) && isset($permissions[$permission->name])) {
                $permission->update(['description' => $permissions[$permission->name]]);
            }
        }

        // Crear/actualizar el rol admin con todos los permisos (incluidos los nuevos)
        // Se obtienen TODOS los permisos de la BD después de crear/actualizar los del seeder
        $adminRole = Role::updateOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['description' => 'Administrador del sistema con acceso completo a todas las funcionalidades']
        );
        
        // Asignar TODOS los permisos existentes al rol admin (incluidos los nuevos que puedan existir)
        $allPermissions = Permission::where('guard_name', 'web')->get();
        $adminRole->syncPermissions($allPermissions);

        // Limpiar caché de permisos de Spatie
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Limpiar caché de permisos de todos los usuarios
        // Esto asegura que los usuarios vean los nuevos permisos inmediatamente
        $this->clearAllUsersPermissionCache();
    }

    /**
     * Clear permission cache for all users.
     * This ensures that users see new permissions immediately after running the seeder.
     */
    private function clearAllUsersPermissionCache(): void
    {
        try {
            $users = User::all();
            $clearedCount = 0;

            foreach ($users as $user) {
                $cacheKey = "user:{$user->id}:permissions";
                Cache::forget($cacheKey);
                $clearedCount++;
            }

            $this->command->info("✓ Caché de permisos limpiado para {$clearedCount} usuarios");
        } catch (\Exception $e) {
            $this->command->warn("⚠ No se pudo limpiar el caché de permisos de usuarios: " . $e->getMessage());
        }
    }
}
