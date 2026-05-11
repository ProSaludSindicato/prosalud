<?php

namespace Tests\Unit;

use App\Services\AssemblyDelegateColumnResolver;
use Tests\TestCase;

class AssemblyDelegateColumnResolverTest extends TestCase
{
    public function test_resolves_standard_six_column_headers(): void
    {
        $header = [
            'CEDULA',
            'NOMBRE Y APELLIDOS',
            'SEDE',
            'ESTADO BD',
            'PROCESO',
            'FECHA DE EXPEDICION',
        ];

        $map = AssemblyDelegateColumnResolver::resolveOrFail($header);

        $this->assertSame(0, $map->cedula);
        $this->assertSame(1, $map->nombreApellidos);
        $this->assertSame(2, $map->sede);
        $this->assertSame(3, $map->estadoBd);
        $this->assertSame(4, $map->proceso);
        $this->assertSame(5, $map->fechaExpedicion);
        $this->assertFalse($map->isLegacyFourColumnLayout());
    }

    public function test_resolves_reordered_columns_by_name(): void
    {
        $header = [
            'FECHA DE EXPEDICION',
            'CEDULA',
            'PROCESO',
            'NOMBRE Y APELLIDOS',
            'SEDE',
            'ESTADO BD',
        ];

        $map = AssemblyDelegateColumnResolver::resolveOrFail($header);

        $this->assertSame(1, $map->cedula);
        $this->assertSame(3, $map->nombreApellidos);
        $this->assertSame(4, $map->sede);
        $this->assertSame(5, $map->estadoBd);
        $this->assertSame(2, $map->proceso);
        $this->assertSame(0, $map->fechaExpedicion);
    }

    public function test_legacy_four_columns_in_columns_a_to_d(): void
    {
        $header = ['CEDULA', 'NOMBRE Y APELLIDOS', 'ESTADO BD', 'F. EXPEDICIÓN'];

        $map = AssemblyDelegateColumnResolver::resolveOrFail($header);

        $this->assertTrue($map->isLegacyFourColumnLayout());
        $this->assertNull($map->sede);
        $this->assertNull($map->proceso);
    }

    public function test_throws_when_sede_proceso_missing_and_columns_extend_past_d(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SEDE y PROCESO');

        $header = [
            'CEDULA',
            'NOMBRE Y APELLIDOS',
            'NOTAS',
            'ESTADO BD',
            'FECHA DE EXPEDICION',
        ];

        AssemblyDelegateColumnResolver::resolveOrFail($header);
    }

    public function test_throws_clear_message_when_cedula_header_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CEDULA');

        AssemblyDelegateColumnResolver::resolveOrFail(['X', 'NOMBRE Y APELLIDOS', 'SEDE', 'ESTADO BD', 'PROCESO', 'FECHA DE EXPEDICION']);
    }
}
