<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
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

        $this->command->info('Usuario de ejemplo creado exitosamente:');
        $this->command->info('- admin@prosalud.com (Admin)');
        $this->command->info('Contraseña: password123');
    }
}
