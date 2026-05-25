<?php

namespace App\Enums;

enum WellnessHospitalScope: string
{
    case Bello = 'bello';
    case Rionegro = 'rionegro';
    case LaMaria = 'la_maria';
    case Carisma = 'carisma';
    case Todos = 'todos';

    public function label(): string
    {
        return match ($this) {
            self::Bello => 'Bello',
            self::Rionegro => 'Rionegro',
            self::LaMaria => 'La María',
            self::Carisma => 'Carisma',
            self::Todos => 'Todos los hospitales',
        };
    }

    public function matchesCostCenter(string $costCenter): bool
    {
        return match ($this) {
            self::Bello => $costCenter === 'Bello',
            self::Rionegro => $costCenter === 'Rionegro',
            self::LaMaria => str_starts_with($costCenter, 'La Maria'),
            self::Carisma => $costCenter === 'Carisma' || str_starts_with($costCenter, 'Carisma'),
            self::Todos => true,
        };
    }

    /**
     * @return list<self>
     */
    public static function forCostCenter(string $costCenter): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $scope) => $scope->matchesCostCenter($costCenter),
        ));
    }
}
