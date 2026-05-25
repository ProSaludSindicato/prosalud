<?php

namespace Tests\Unit;

use App\Support\HospitalCatalog;
use Tests\TestCase;

class HospitalCatalogTest extends TestCase
{
    public function test_resolve_e_s_ecarisma(): void
    {
        $this->assertSame('E.S.E. Hospital Carisma', HospitalCatalog::resolve('E.S.ECARISMA'));
    }

    public function test_resolve_trims_whitespace(): void
    {
        $this->assertSame('E.S.E. Hospital Carisma', HospitalCatalog::resolve('E.S.ECARISMA '));
    }

    public function test_resolve_carisma_admon_alias(): void
    {
        $this->assertSame('E.S.E. Hospital Carisma', HospitalCatalog::resolve('E.S.E CARISMA ADMON '));
    }

    public function test_resolve_esecarisma_typo_alias(): void
    {
        $this->assertSame('E.S.E. Hospital Carisma', HospitalCatalog::resolve('ESECARISMA'));
    }

    public function test_resolve_empty_returns_empty(): void
    {
        $this->assertSame('', HospitalCatalog::resolve(null));
        $this->assertSame('', HospitalCatalog::resolve(''));
        $this->assertSame('', HospitalCatalog::resolve('   '));
    }

    public function test_resolve_case_insensitive_key(): void
    {
        $this->assertSame('E.S.E. Hospital Marco Fidel Suarez de Bello', HospitalCatalog::resolve('bello'));
    }

    public function test_resolve_unknown_returns_trimmed_original(): void
    {
        $this->assertSame('CODIGO-DESCONOCIDO', HospitalCatalog::resolve('  CODIGO-DESCONOCIDO  '));
    }
}
