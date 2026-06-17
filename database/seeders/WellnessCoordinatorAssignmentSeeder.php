<?php

namespace Database\Seeders;

use App\Enums\WellnessHospitalScope;
use App\Models\User;
use App\Models\WellnessHospitalAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class WellnessCoordinatorAssignmentSeeder extends Seeder
{
    /**
     * @var array<string, list<WellnessHospitalScope>>
     */
    private const COORDINATOR_ASSIGNMENTS = [
        'coordinadorhlm.sprosalud@gmail.com' => [
            WellnessHospitalScope::LaMaria,
            WellnessHospitalScope::Carisma,
        ],
        'beatrizbernal1104@gmail.com' => [
            WellnessHospitalScope::LaMaria,
        ],
        'coordinacionprosalud@hmfs.gov.co' => [
            WellnessHospitalScope::Bello,
        ],
        'sprosalud.hmfs01@gmail.com' => [
            WellnessHospitalScope::Bello,
        ],
        'coordinacionsp@hospitalrionegro.gov.co' => [
            WellnessHospitalScope::Rionegro,
        ],
        'sst.sindicato@gmail.com' => [
            WellnessHospitalScope::Todos,
        ],
        'sstjuanyepesr@gmail.com' => [
            WellnessHospitalScope::Todos,
        ],
    ];

    public function run(): void
    {
        foreach (self::COORDINATOR_ASSIGNMENTS as $email => $scopes) {
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                Log::warning('Wellness coordinator not found for hospital assignment', [
                    'email' => $email,
                ]);

                continue;
            }

            foreach ($scopes as $scope) {
                WellnessHospitalAssignment::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'hospital_scope' => $scope->value,
                    ],
                    [],
                );
            }
        }
    }
}
