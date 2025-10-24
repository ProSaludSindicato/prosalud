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

        // Create permissions
        $permissions = [
            // Request Forms permissions
            'request_forms.view',
            'request_forms.create',
            'request_forms.edit',
            'request_forms.delete',

            // Wellness Events permissions
            'wellness_events.view',
            'wellness_events.create',
            'wellness_events.edit',
            'wellness_events.delete',

            // Comfenalco Events permissions
            'comfenalco_events.view',
            'comfenalco_events.create',
            'comfenalco_events.edit',
            'comfenalco_events.delete',

            // Users permissions
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',

            // Roles and permissions management
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            'permissions.view',
            'permissions.edit',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign permissions
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->givePermissionTo(Permission::all());

        $auxiliarRole = Role::create(['name' => 'auxiliar', 'guard_name' => 'web']);
        $auxiliarRole->givePermissionTo([
            'request_forms.view',
            'request_forms.create',
            'request_forms.edit',
            'request_forms.delete',
            'wellness_events.view',
            'wellness_events.create',
            'wellness_events.edit',
            'wellness_events.delete',
            'comfenalco_events.view',
            'comfenalco_events.create',
            'comfenalco_events.edit',
            'comfenalco_events.delete',
        ]);

        $sstRole = Role::create(['name' => 'sst', 'guard_name' => 'web']);
        $sstRole->givePermissionTo([
            'wellness_events.view',
            'wellness_events.create',
            'wellness_events.edit',
            'wellness_events.delete',
            'comfenalco_events.view',
            'comfenalco_events.create',
            'comfenalco_events.edit',
            'comfenalco_events.delete',
        ]);

        $tecnicoRole = Role::create(['name' => 'técnico', 'guard_name' => 'web']);
        $tecnicoRole->givePermissionTo([
            'wellness_events.view',
            'wellness_events.create',
            'wellness_events.edit',
            'wellness_events.delete',
            'comfenalco_events.view',
            'comfenalco_events.create',
            'comfenalco_events.edit',
            'comfenalco_events.delete',
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
        ]);
    }
}
