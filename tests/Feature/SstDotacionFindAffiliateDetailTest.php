<?php

namespace Tests\Feature;

use App\Services\AfiliadoService;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SstDotacionFindAffiliateDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.prosanet.enabled' => true,
            'services.prosanet.base_url' => 'https://api.prosanet.test/index.php',
            'services.prosanet.username' => 'api_user',
            'services.prosanet.password' => 'api_pass',
            'services.prosanet.timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_find_affiliate_uses_detail_api_and_returns_convenio_fields(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'detail-token',
                'expireIn' => (string) (time() + 3600),
            ]),
            'https://api.prosanet.test/index.php*' => Http::response([
                'items' => [
                    [
                        'personal_information' => [
                            'document_type_label' => 'CC',
                            'document_number' => '1234567890',
                            'first_name' => 'JUAN',
                            'last_name' => 'PEREZ',
                            'status' => 'Activo',
                        ],
                        'covenants' => [
                            [
                                'admission_date' => '2023-12-14',
                                'end_date' => null,
                                'client_business_name' => 'HMFS - BELLO',
                                'branch_name' => 'GRUPO 1',
                                'charge_name' => 'BACTERIOLOGO(A)',
                            ],
                        ],
                        'beneficiaries' => [],
                    ],
                ],
                'pagination' => [
                    'page' => 1,
                    'pageSize' => 10,
                    'totalCount' => 1,
                    'pageCount' => 1,
                ],
            ]),
        ]);

        $affiliate = app(SstDotacionService::class)->findAffiliate('CC', '1234567890');

        $this->assertNotNull($affiliate);
        $this->assertSame('CC-1234567890', $affiliate['id']);
        $this->assertSame('JUAN', $affiliate['firstName']);
        $this->assertSame('PEREZ', $affiliate['lastName']);
        $this->assertSame('HMFS - BELLO', $affiliate['hospital']);
        $this->assertSame('BACTERIOLOGO(A)', $affiliate['role']);
        $this->assertSame('Activo', $affiliate['convenioStatus']);
        $this->assertTrue($affiliate['active']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'r=employees-api/detail')
                && str_contains($request->url(), 'document_number=1234567890')
                && str_contains($request->url(), 'document_type=1');
        });
    }

    public function test_find_affiliate_returns_null_when_detail_api_has_no_match(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'detail-token',
                'expireIn' => (string) (time() + 3600),
            ]),
            'https://api.prosanet.test/index.php*' => Http::response([
                'items' => [],
                'pagination' => [
                    'page' => 1,
                    'pageSize' => 10,
                    'totalCount' => 0,
                    'pageCount' => 0,
                ],
            ]),
        ]);

        $affiliate = app(SstDotacionService::class)->findAffiliate('CC', '9999999999');

        $this->assertNull($affiliate);
    }

    public function test_find_affiliate_falls_back_to_basic_list_when_api_is_disabled(): void
    {
        config(['services.prosanet.enabled' => false]);

        $afiliadoMock = Mockery::mock(AfiliadoService::class);
        $afiliadoMock->shouldReceive('getAfiliadoBasicWithConvenios')
            ->once()
            ->with('CC', '1234567890')
            ->andReturn(false);
        $afiliadoMock->shouldReceive('isProsanetApiEnabled')->andReturn(false);
        $afiliadoMock->shouldReceive('getAllAfiliadosBasic')->once()->andReturn([
            [
                'tipo_documento' => 'CC',
                'documento' => '1234567890',
                'nombres' => 'Ana',
                'apellidos' => 'Lopez',
                'estado' => 'ACTIVO',
                'convenios' => [
                    [
                        'cliente' => 'HOSPITAL TEST',
                        'proceso' => 'ENFERMERA',
                        'estado' => 'Activo',
                        'fecha_ingreso' => '2024-01-01',
                        'fecha_fin' => null,
                    ],
                ],
            ],
        ]);

        $affiliate = (new SstDotacionService($afiliadoMock))->findAffiliate('CC', '1234567890');

        $this->assertNotNull($affiliate);
        $this->assertSame('HOSPITAL TEST', $affiliate['hospital']);
        $this->assertSame('ENFERMERA', $affiliate['role']);
    }

    public function test_afiliado_service_get_basic_with_convenios_maps_detail_response(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'detail-token',
                'expireIn' => (string) (time() + 3600),
            ]),
            'https://api.prosanet.test/index.php*' => Http::response([
                'items' => [
                    [
                        'personal_information' => [
                            'document_type_label' => 'CC',
                            'document_number' => '1035228093',
                            'first_name' => 'LUISA',
                            'last_name' => 'ACEVEDO',
                            'status' => 'Activo',
                        ],
                        'covenants' => [
                            [
                                'admission_date' => '2023-12-14',
                                'end_date' => null,
                                'client_business_name' => 'HMFS - BELLO',
                                'charge_name' => 'BACTERIOLOGO(A)',
                            ],
                        ],
                        'beneficiaries' => [],
                    ],
                ],
                'pagination' => [
                    'page' => 1,
                    'pageSize' => 10,
                    'totalCount' => 1,
                    'pageCount' => 1,
                ],
            ]),
        ]);

        $result = app(AfiliadoService::class)->getAfiliadoBasicWithConvenios('CC', '1035228093');

        $this->assertIsArray($result);
        $this->assertSame('CC', $result['tipo_documento']);
        $this->assertSame('1035228093', $result['documento']);
        $this->assertCount(1, $result['convenios']);
        $this->assertSame('HMFS - BELLO', $result['convenios'][0]['cliente']);
        $this->assertSame('BACTERIOLOGO(A)', $result['convenios'][0]['proceso']);
        $this->assertSame('Activo', $result['convenios'][0]['estado']);
    }
}
