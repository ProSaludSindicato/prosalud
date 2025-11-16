<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@prosalud.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['admin']);

        $auxiliar = User::firstOrCreate(
            ['email' => 'auxiliar@prosalud.com'],
            [
                'name' => 'Auxiliar de Gestión',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        if (!$auxiliar->hasRole('auxiliar')) {
            $auxiliar->assignRole('auxiliar');
        }

        $sst = User::firstOrCreate(
            ['email' => 'sst@prosalud.com'],
            [
                'name' => 'Especialista SST',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        if (!$sst->hasRole('sst')) {
            $sst->assignRole('sst');
        }

        $tecnico = User::firstOrCreate(
            ['email' => 'tecnico@prosalud.com'],
            [
                'name' => 'Técnico de Sistemas',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        if (!$tecnico->hasRole('técnico')) {
            $tecnico->assignRole('técnico');
        }

        $this->command->info('Usuarios de ejemplo creados exitosamente:');
        $this->command->info('- admin@prosalud.com (Admin)');
        $this->command->info('- auxiliar@prosalud.com (Auxiliar)');
        $this->command->info('- sst@prosalud.com (SST)');
        $this->command->info('- tecnico@prosalud.com (Técnico)');
        $this->command->info('Contraseña para todos: password123');
    }
}
