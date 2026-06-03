<?php

namespace Tests\Unit;

use App\Models\RequestForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequestFormEpsFormatTest extends TestCase
{
    #[DataProvider('epsFormatProvider')]
    public function test_format_payload_value_maps_eps_slugs(string $slug, string $expectedLabel): void
    {
        $requestForm = RequestForm::factory()->make();

        $this->assertSame($expectedLabel, $requestForm->formatPayloadValue('eps', $slug));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function epsFormatProvider(): array
    {
        return [
            'nueva eps with dots label' => ['nueva_eps', 'NUEVA E.P.S.'],
            'nueva eps movilidad' => ['nueva_eps_movilidad', 'NUEVA EPS MOVILIDAD'],
            'sura legacy name' => ['sura', 'EPS SURA (ANTES SUSALUD)'],
            'coosalud' => ['coosalud', 'COOSALUD EPS'],
            'salud total' => ['salud_total', 'SALUD TOTAL'],
            'ninguna' => ['ninguna', 'NINGUNA'],
        ];
    }

    #[DataProvider('afpFormatProvider')]
    public function test_format_payload_value_maps_afp_slugs(string $slug, string $expectedLabel): void
    {
        $requestForm = RequestForm::factory()->make();

        $this->assertSame($expectedLabel, $requestForm->formatPayloadValue('afp', $slug));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function afpFormatProvider(): array
    {
        return [
            'colpensiones' => ['colpensiones', 'COLPENSIONES'],
            'old mutual obligatorio' => ['old_mutual', 'OLD MUTUAL OBLIGATORIO'],
            'pensionado' => ['pensionado', 'PENSIONADO (A)'],
            'proteccion' => ['proteccion', 'PROTECCION'],
        ];
    }
}
