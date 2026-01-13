<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(ConfigurationSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(HospitalSeeder::class);
        $this->call(InventorySeeder::class);
        $this->call(InventoryProductSeeder::class);
    }
}
