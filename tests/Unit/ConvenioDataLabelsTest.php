<?php

namespace Tests\Unit;

use App\Support\ConvenioDataLabels;
use PHPUnit\Framework\TestCase;

class ConvenioDataLabelsTest extends TestCase
{
    public function test_present_formats_money_fields(): void
    {
        $fields = ConvenioDataLabels::present([
            'numero_documento' => '1000918728',
            'basico' => 2000000,
            'auxilios' => 560000,
            'horas' => 186,
        ]);

        $valuesByKey = collect($fields)->pluck('value', 'key');

        $this->assertSame('1000918728', $valuesByKey['numero_documento']);
        $this->assertSame('$2.000.000', $valuesByKey['basico']);
        $this->assertSame('$560.000', $valuesByKey['auxilios']);
        $this->assertSame('186', $valuesByKey['horas']);
    }

    public function test_present_formats_unknown_money_like_keys(): void
    {
        $fields = ConvenioDataLabels::present([
            't_basicos' => 1500000,
        ]);

        $this->assertSame('$1.500.000', $fields[0]['value']);
    }

    public function test_format_money_returns_original_string_when_not_numeric(): void
    {
        $this->assertSame('N/A', ConvenioDataLabels::formatMoney('N/A'));
    }
}
