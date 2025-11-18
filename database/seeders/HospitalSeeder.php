<?php

namespace Database\Seeders;

use App\Models\{Hospital, InventoryLocation};
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class HospitalSeeder extends Seeder
{
    public function run(): void
    {
        $hospitals = [
            [
                'name' => 'Admon',
                'type' => 'warehouse',
                'is_primary' => true,
            ],
            [
                'name' => 'Hospital La María',
                'type' => 'hospital',
            ],
            [
                'name' => 'Hospital Rionegro',
                'type' => 'hospital',
            ],
            [
                'name' => 'Hospital Bello',
                'type' => 'hospital',
            ],
        ];

        foreach ($hospitals as $hospitalData) {
            $hospital = Hospital::query()->firstOrCreate(
                ['name' => $hospitalData['name']],
                [
                    'type' => $hospitalData['type'],
                ]
            );

            if (!$hospital->wasRecentlyCreated) {
                $hospital->update(['type' => $hospitalData['type']]);
            }

            $location = $hospital->locations()->first();

            if (!$location) {
                $hospital->locations()->create([
                    'id' => (string) Str::uuid(),
                    'name' => $hospitalData['name'],
                    'type' => 'warehouse' === $hospitalData['type'] ? 'warehouse' : 'hospital',
                    'is_primary' => $hospitalData['is_primary'] ?? false,
                ]);
            } else {
                $location->update([
                    'name' => $hospitalData['name'],
                    'type' => 'warehouse' === $hospitalData['type'] ? 'warehouse' : 'hospital',
                    'is_primary' => $hospitalData['is_primary'] ?? false,
                ]);
            }
        }

        // Ensure only one primary location
        $primary = InventoryLocation::query()->where('is_primary', true)->first();
        if (!$primary) {
            InventoryLocation::query()->first()?->update(['is_primary' => true]);
        }
    }
}
