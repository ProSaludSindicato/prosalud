<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

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

        // Crear/actualizar roles con sus descripciones
        $roles = [
            'admin' => [
                'description' => 'Administrador del sistema con acceso completo a todas las funcionalidades',
                'permissions' => Permission::all(), // Todos los permisos
            ],
            'auxiliar' => [
                'description' => 'Auxiliar de gestión con acceso a solicitudes, eventos de bienestar y eventos Comfenalco',
                'permissions' => Permission::whereIn('name', [
                    'requests.view',
                    'requests.create',
                    'requests.update_status',
                    'requests.respond',
                    'requests.download_files',
                    'wellness_events.manage',
                    'wellness_requests.view',
                    'wellness_requests.manage',
                    'wellness_activity.manage',
                    'comfenalco_events.manage',
                ])->get(),
            ],
            'sst' => [
                'description' => 'Especialista en Seguridad y Salud en el Trabajo con acceso a eventos de bienestar y eventos Comfenalco',
                'permissions' => Permission::whereIn('name', [
                    'wellness_events.manage',
                    'wellness_requests.view',
                    'wellness_requests.manage',
                    'wellness_activity.manage',
                    'comfenalco_events.manage',
                ])->get(),
            ],
            'técnico' => [
                'description' => 'Técnico de sistemas con acceso a gestión de usuarios, eventos de bienestar y eventos Comfenalco',
                'permissions' => Permission::whereIn('name', [
                    'users.manage',
                    'wellness_events.manage',
                    'wellness_requests.view',
                    'wellness_requests.manage',
                    'wellness_activity.manage',
                    'comfenalco_events.manage',
                ])->get(),
            ],
        ];

        foreach ($roles as $roleName => $roleData) {
            $role = Role::updateOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['description' => $roleData['description']]
            );
            $role->syncPermissions($roleData['permissions']);
        }
    }
}
