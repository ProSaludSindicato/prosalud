<?php

namespace Tests\Feature;

use App\Models\SstDeliveryItem;
use App\Models\SstDeliveryRecord;
use App\Services\AfiliadoService;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SstDotacionCreateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const MINIMAL_PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.channels.single.level' => 'debug']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_delivery_uses_targeted_affiliate_lookup_and_registers_carnet(): void
    {
        Event::fake([MessageLogged::class]);
        Storage::fake('prosalud-private');

        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('getAfiliadoBasicWithConvenios')
            ->once()
            ->with('CC', '1000764643')
            ->andReturn([
                'tipo_documento' => 'CC',
                'documento' => '1000764643',
                'nombres' => 'Juliana',
                'apellidos' => 'Ramírez',
                'estado' => 'ACTIVO',
                'convenios' => [
                    [
                        'cliente' => 'HMFS - BELLO',
                        'proceso' => 'AUXILIAR',
                        'estado' => 'Activo',
                        'fecha_ingreso' => '2024-01-01',
                        'fecha_fin' => null,
                    ],
                ],
            ]);
        $afiliadoMock->shouldNotReceive('getAllAfiliadosBasic');

        $service = new SstDotacionService($afiliadoMock);

        $result = $service->createDelivery([
            'affiliateId' => 'CC-1000764643',
            'affiliateDocumentType' => 'CC',
            'affiliateDocumentNumber' => '1000764643',
            'items' => [
                ['itemId' => '__carnet__', 'quantity' => 1],
            ],
            'signatureData' => self::MINIMAL_PNG_DATA_URL,
            'signedDocumentType' => 'CC',
            'signedDocumentNumber' => '1000764643',
            'deliveryType' => 'first_time',
        ]);

        $this->assertSame('CC-1000764643', $result['affiliateId']);
        $this->assertSame('first_time', $result['deliveryType']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('__carnet__', $result['items'][0]['itemId']);
        $this->assertSame('Carnet', $result['items'][0]['name']);

        $this->assertDatabaseHas('sst_delivery_records', [
            'affiliate_id' => 'CC-1000764643',
            'affiliate_first_name' => 'Juliana',
            'affiliate_last_name' => 'Ramírez',
            'delivery_type' => 'first_time',
        ]);

        $record = SstDeliveryRecord::query()->where('affiliate_id', 'CC-1000764643')->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->signature_path);
        Storage::disk('prosalud-private')->assertExists($record->signature_path);

        $this->assertSame(1, SstDeliveryItem::query()->where('delivery_id', $record->id)->count());

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'info'
                && ($event->context['action'] ?? null) === 'delivery_created'
                && ($event->context['affiliate_id'] ?? null) === 'CC-1000764643'
                && ($event->context['delivery_type'] ?? null) === 'first_time';
        });
    }

    public function test_create_delivery_throws_when_affiliate_not_found(): void
    {
        Event::fake([MessageLogged::class]);

        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('getAfiliadoBasicWithConvenios')
            ->once()
            ->with('CC', '9999999999')
            ->andReturn(null);
        $afiliadoMock->shouldReceive('getAllAfiliadosBasic')
            ->once()
            ->andReturn([]);
        // Diagnostics build the full collection when it's safe/cheap to do so (cache already warm).
        $afiliadoMock->shouldReceive('isProsanetApiEnabled')->andReturn(true);
        $afiliadoMock->shouldReceive('isAllAfiliadosBasicCacheWarm')->andReturn(true);

        $service = new SstDotacionService($afiliadoMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se encontró el afiliado solicitado.');

        $service->createDelivery([
            'affiliateId' => 'CC-9999999999',
            'affiliateDocumentType' => 'CC',
            'affiliateDocumentNumber' => '9999999999',
            'items' => [
                ['itemId' => '__carnet__', 'quantity' => 1],
            ],
            'signatureData' => self::MINIMAL_PNG_DATA_URL,
            'signedDocumentType' => 'CC',
            'signedDocumentNumber' => '9999999999',
            'deliveryType' => 'first_time',
        ]);
    }

    public function test_create_delivery_logs_warning_when_signature_format_is_invalid(): void
    {
        Event::fake([MessageLogged::class]);

        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('getAfiliadoBasicWithConvenios')
            ->once()
            ->with('CC', '1000764643')
            ->andReturn([
                'tipo_documento' => 'CC',
                'documento' => '1000764643',
                'nombres' => 'Juliana',
                'apellidos' => 'Ramírez',
                'estado' => 'ACTIVO',
                'convenios' => [],
            ]);

        $service = new SstDotacionService($afiliadoMock);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Formato de firma inválido.');

        $service->createDelivery([
            'affiliateId' => 'CC-1000764643',
            'affiliateDocumentType' => 'CC',
            'affiliateDocumentNumber' => '1000764643',
            'items' => [
                ['itemId' => '__carnet__', 'quantity' => 1],
            ],
            'signatureData' => 'not-a-valid-data-url',
            'signedDocumentType' => 'CC',
            'signedDocumentNumber' => '1000764643',
            'deliveryType' => 'first_time',
        ]);

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && ($event->context['action'] ?? null) === 'signature_store_failed'
                && ($event->context['cause'] ?? null) === 'invalid_format';
        });
    }
}
