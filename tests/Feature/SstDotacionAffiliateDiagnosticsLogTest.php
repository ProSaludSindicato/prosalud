<?php

namespace Tests\Feature;

use App\Services\AfiliadoService;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class SstDotacionAffiliateDiagnosticsLogTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_affiliates_returns_empty_total_when_basic_list_is_empty(): void
    {
        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('getAllAfiliadosBasic')->once()->andReturn([]);

        $service = new SstDotacionService($afiliadoMock);

        $result = $service->getAffiliates(['page' => 1]);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    public function test_find_affiliate_missing_logs_info_when_document_exists_with_different_type(): void
    {
        Event::fake([MessageLogged::class]);

        $payload = [
            [
                'tipo_documento' => 'TI',
                'documento' => '9998777',
                'nombres' => 'Ana',
                'apellidos' => 'Prueba',
                'estado' => 'ACTIVO',
                'convenios' => [],
            ],
        ];

        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('getAllAfiliadosBasic')->once()->andReturn($payload);

        $service = new SstDotacionService($afiliadoMock);

        $service->logAffiliateMissDiagnostics('CC', '9998777');

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'info'
                && isset($event->context['cause'], $event->context['document_types_found_for_number'])
                && $event->context['cause'] === 'document_type_mismatch'
                && $event->context['document_types_found_for_number'] === ['TI'];
        });
    }
}
