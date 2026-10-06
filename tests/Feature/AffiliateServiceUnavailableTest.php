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

    public function test_find_affiliate_fails_fast_when_detail_lookup_fails_and_catalog_cache_is_cold(): void
    {
        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('getAfiliadoBasicWithConvenios')
            ->once()
            ->with('CC', '1000764643')
            ->andReturn(false);
        $afiliadoMock->shouldReceive('isProsanetApiEnabled')->andReturn(true);
        $afiliadoMock->shouldReceive('isAllAfiliadosBasicCacheWarm')->andReturn(false);
        $afiliadoMock->shouldNotReceive('getAllAfiliadosBasic');

        $service = new SstDotacionService($afiliadoMock);

        $this->expectException(AffiliateServiceUnavailableException::class);

        $service->findAffiliate('CC', '1000764643');
    }

    public function test_find_affiliate_uses_cached_catalog_when_detail_lookup_fails_but_cache_is_warm(): void
    {
        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('getAfiliadoBasicWithConvenios')
            ->once()
            ->with('CC', '1000764643')
            ->andReturn(false);
        $afiliadoMock->shouldReceive('isProsanetApiEnabled')->andReturn(true);
        $afiliadoMock->shouldReceive('isAllAfiliadosBasicCacheWarm')->andReturn(true);
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

        $service = new SstDotacionService($afiliadoMock);

        $affiliate = $service->findAffiliate('CC', '1000764643');

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
