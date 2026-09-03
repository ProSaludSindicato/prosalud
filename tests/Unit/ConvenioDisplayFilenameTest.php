<?php

namespace Tests\Unit;

use App\Enums\ConvenioPdfStage;
use App\Models\ConvenioEmailTracking;
use App\Support\ConvenioDisplayFilename;
use App\Support\ConvenioPreGeneratedPdfFilename;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConvenioDisplayFilenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_standard_filename_with_period(): void
    {
        $filename = ConvenioDisplayFilename::build(
            'BELLO',
            'ACEVEDO MONTOYA LUISA FERNANDA',
            '1035228093',
            '20262',
        );

        $this->assertSame(
            'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093 - 20262.pdf',
            $filename,
        );
    }

    public function test_builds_filename_without_period_when_not_provided(): void
    {
        $filename = ConvenioDisplayFilename::build(
            'BELLO',
            'ACEVEDO MONTOYA LUISA FERNANDA',
            '1035228093',
        );

        $this->assertSame(
            'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093.pdf',
            $filename,
        );
    }

    public function test_resolves_period_from_enviado_at(): void
    {
        $this->assertSame(
            '20261',
            ConvenioDisplayFilename::resolvePeriodoFromEnviadoAt(Carbon::parse('2026-01-15')),
        );
        $this->assertSame(
            '20262',
            ConvenioDisplayFilename::resolvePeriodoFromEnviadoAt(Carbon::parse('2026-09-01')),
        );
    }

    public function test_from_tracking_uses_sede_nombre_and_enviado_at_period(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1035228093',
            'nombre_afiliado' => 'ACEVEDO MONTOYA LUISA FERNANDA',
            'nombre_convenio' => 'PROCESO TEST',
            'sede' => 'Bello',
            'enviado_at' => Carbon::parse('2026-09-01 10:00:00'),
        ]);

        $this->assertSame(
            'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093 - 20262.pdf',
            ConvenioDisplayFilename::fromTracking($tracking),
        );
    }

    public function test_storage_filename_adds_stage_suffix_for_signed_pdf(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '1035228093',
            'nombre_afiliado' => 'ACEVEDO MONTOYA LUISA FERNANDA',
            'sede' => 'BELLO',
            'enviado_at' => Carbon::parse('2026-09-01'),
        ]);

        $this->assertSame(
            'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093 - 20262 - firmado-afiliado.pdf',
            ConvenioDisplayFilename::storageFileName($tracking, ConvenioPdfStage::FirmadoAfiliado),
        );
    }

    public function test_pre_generated_parser_supports_optional_period_suffix(): void
    {
        $parsed = ConvenioPreGeneratedPdfFilename::parse(
            'BELLO - ACEVEDO MONTOYA LUISA FERNANDA - 1035228093 - 20262.pdf'
        );

        $this->assertNotNull($parsed);
        $this->assertSame('1035228093', $parsed['documento']);
        $this->assertSame('BELLO', $parsed['nombre_convenio']);
        $this->assertSame('ACEVEDO MONTOYA LUISA FERNANDA', $parsed['nombre_afiliado']);
        $this->assertSame('20262', $parsed['periodo']);
    }
}
