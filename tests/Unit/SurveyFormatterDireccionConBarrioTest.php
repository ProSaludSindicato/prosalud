<?php

namespace Tests\Unit;

use App\Helpers\SurveyFormatter;
use App\Models\SocioDemographicSurvey;
use PHPUnit\Framework\TestCase;

class SurveyFormatterDireccionConBarrioTest extends TestCase
{
    public function test_concatenates_direccion_and_barrio_with_comma(): void
    {
        $this->assertSame(
            'CALLE 50 # 45-23, LAURELES',
            SurveyFormatter::formatDireccionConBarrio('CALLE 50 # 45-23', 'LAURELES')
        );
    }

    public function test_returns_only_direccion_when_barrio_is_missing(): void
    {
        $this->assertSame(
            'CALLE 50 # 45-23',
            SurveyFormatter::formatDireccionConBarrio('CALLE 50 # 45-23', null)
        );
    }

    public function test_returns_only_barrio_when_direccion_is_missing(): void
    {
        $this->assertSame(
            'LAURELES',
            SurveyFormatter::formatDireccionConBarrio(null, 'LAURELES')
        );
    }

    public function test_model_accessor_uses_same_format(): void
    {
        $survey = new SocioDemographicSurvey([
            'direccion' => 'CALLE 50 # 45-23',
            'barrio' => 'LAURELES',
        ]);

        $this->assertSame('CALLE 50 # 45-23, LAURELES', $survey->direccion_completa);
    }
}
