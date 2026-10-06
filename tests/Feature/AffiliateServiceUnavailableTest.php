<?php

namespace Tests\Feature;

use App\Exceptions\AffiliateServiceUnavailableException;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\AfiliadoService;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers the incident where registering a single delivery from the panel triggered a full
 * ProSanet catalog resync (50+ paginated requests) because the per-document "detail" API call
 * failed and findAffiliate() unconditionally fell back to rebuilding the whole catalog.
 */
class AffiliateServiceUnavailableTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * With the API enabled, the convenio-less summary catalog is never an acceptable fallback:
     * it would yield hospital 'SIN ASIGNAR' and role null, silently persisting wrong data.
     */
    public function test_find_affiliate_fails_instead_of_falling_back_to_the_convenio_less_catalog(): void
    {
        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('isProsanetApiEnabled')->andReturn(true);
        $afiliadoMock->shouldReceive('getAfiliadoBasicWithConvenios')
            ->once()
            ->with('CC', '1000764643')
            ->andReturn(false);
        $afiliadoMock->shouldNotReceive('getAllAfiliadosBasic');

        $service = new SstDotacionService($afiliadoMock);

        $this->expectException(AffiliateServiceUnavailableException::class);

        $service->findAffiliate('CC', '1000764643');
    }

    public function test_find_affiliate_keeps_hospital_and_role_from_the_detail_api(): void
    {
        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('isProsanetApiEnabled')->andReturn(true);
        $afiliadoMock->shouldReceive('getAfiliadoBasicWithConvenios')
            ->once()
            ->with('CC', '1001509956')
            ->andReturn([
                'tipo_documento' => 'CC',
                'documento' => '1001509956',
                'nombres' => 'Juliana',
                'apellidos' => 'Ramírez',
                'estado' => 'ACTIVO',
                'convenios' => [
                    [
                        'cliente' => 'HMFS - BELLO',
                        'proceso' => 'ENFERMERO(A) PROFESIONAL - URGENCIAS',
                        'estado' => 'Activo',
                        'fecha_ingreso' => '2024-01-01',
                        'fecha_fin' => null,
                    ],
                ],
            ]);
        $afiliadoMock->shouldNotReceive('getAllAfiliadosBasic');

        $affiliate = (new SstDotacionService($afiliadoMock))->findAffiliate('CC', '1001509956');

        $this->assertSame('HMFS - BELLO', $affiliate['hospital']);
        $this->assertSame('ENFERMERO(A) PROFESIONAL - URGENCIAS', $affiliate['role']);
    }

    public function test_find_affiliate_uses_excel_catalog_when_api_is_disabled(): void
    {
        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('isProsanetApiEnabled')->andReturn(false);
        $afiliadoMock->shouldReceive('getAllAfiliadosBasic')->once()->andReturn([
            [
                'tipo_documento' => 'CC',
                'documento' => '1000764643',
                'nombres' => 'Juliana',
                'apellidos' => 'Ramírez',
                'estado' => 'ACTIVO',
                'convenios' => [
                    ['cliente' => 'HMFS - BELLO', 'proceso' => 'AUXILIAR', 'estado' => 'Activo'],
                ],
            ],
        ]);

        $affiliate = (new SstDotacionService($afiliadoMock))->findAffiliate('CC', '1000764643');

        $this->assertNotNull($affiliate);
        $this->assertSame('HMFS - BELLO', $affiliate['hospital']);
    }

    public function test_store_delivery_returns_503_when_affiliate_service_is_unavailable(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $user = User::factory()->create();
        Permission::findOrCreate('dotacion.view');
        Permission::findOrCreate('dotacion.deliveries.create');
        $user->givePermissionTo(['dotacion.view', 'dotacion.deliveries.create']);

        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $dotacionMock = Mockery::mock(SstDotacionService::class);
        $dotacionMock->shouldReceive('createDelivery')
            ->once()
            ->andThrow(new AffiliateServiceUnavailableException);
        $this->app->instance(SstDotacionService::class, $dotacionMock);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $plainToken)
            ->post('/api/dotacion-epp/deliveries', [
                'affiliateId' => 'CC-1000764643',
                'affiliateDocumentType' => 'CC',
                'affiliateDocumentNumber' => '1000764643',
                'items' => [
                    ['itemId' => '__carnet__', 'quantity' => 1],
                ],
                'signatureData' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                'signedDocumentType' => 'CC',
                'signedDocumentNumber' => '1000764643',
                'deliveryType' => 'first_time',
            ]);

        $response->assertStatus(503);
    }
}
