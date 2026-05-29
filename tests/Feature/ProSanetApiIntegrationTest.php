<?php

namespace Tests\Feature;

use App\Exceptions\ProSanetApiException;
use App\Services\AfiliadoService;
use App\Services\ProSanetApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProSanetApiIntegrationTest extends TestCase
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
            'services.prosanet.token_cache_ttl_minutes' => 55,
        ]);
    }

    public function test_prosanet_api_service_obtains_and_caches_token(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'test-jwt-token',
                'expireIn' => (string) (time() + 3600),
            ]),
        ]);

        $service = app(ProSanetApiService::class);

        $tokenFirst = $service->getAccessToken();
        $tokenSecond = $service->getAccessToken();

        $this->assertSame('test-jwt-token', $tokenFirst);
        $this->assertSame('test-jwt-token', $tokenSecond);
        Http::assertSentCount(1);
    }

    public function test_prosanet_api_service_get_detail_returns_items(): void
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
                            'document_type' => 1,
                            'document_type_label' => 'CC',
                            'document_number' => '1035228093',
                            'first_name' => 'LUISA',
                            'last_name' => 'ACEVEDO',
                            'status' => 'Activo',
                            'expedition_date' => '2010-11-12',
                            'mobile_phone_number' => '3005284696',
                            'email' => 'test@example.com',
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

        $response = app(ProSanetApiService::class)->getDetail([
            'document_type' => 1,
            'document_number' => '1035228093',
            'document_type_label' => 'CC',
            'expedition_date' => '2010-11-12',
        ]);

        $this->assertCount(1, $response['items']);
        $this->assertSame('1035228093', $response['items'][0]['personal_information']['document_number']);
    }

    public function test_prosanet_api_service_throws_on_server_error(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'detail-token',
                'expireIn' => (string) (time() + 3600),
            ]),
            'https://api.prosanet.test/index.php*' => Http::response('Server Error', 503),
        ]);

        $this->expectException(ProSanetApiException::class);

        app(ProSanetApiService::class)->getDetail([
            'document_number' => '1035228093',
        ]);
    }

    public function test_prosanet_api_service_sends_route_query_param_on_get(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'detail-token',
                'expireIn' => (string) (time() + 3600),
            ]),
            'https://api.prosanet.test/index.php?r=employees-api/summary*' => Http::response([
                'items' => [],
                'pagination' => ['page' => 1, 'pageSize' => 100, 'totalCount' => 0, 'pageCount' => 0],
            ]),
        ]);

        app(ProSanetApiService::class)->getSummary([
            'page' => 1,
            'per-page' => 100,
        ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'r=employees-api/summary')
                && str_contains($request->url(), 'page=1')
                && str_contains($request->url(), 'per-page=100');
        });
    }

    public function test_prosanet_api_service_includes_request_context_on_client_error(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'detail-token',
                'expireIn' => (string) (time() + 3600),
            ]),
            'https://api.prosanet.test/index.php*' => Http::response('{"message":"Not Found"}', 404),
        ]);

        try {
            app(ProSanetApiService::class)->getSummary([
                'page' => 1,
                'per-page' => 100,
            ]);
            $this->fail('Expected ProSanetApiException was not thrown.');
        } catch (ProSanetApiException $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame('GET', $e->requestMethod);
            $this->assertStringContainsString('employees-api/summary', (string) $e->requestUrl);
            $this->assertStringContainsString('page=1', (string) $e->requestUrl);
            $this->assertStringContainsString('per-page=100', (string) $e->requestUrl);
            $this->assertSame('{"message":"Not Found"}', $e->responseBody);
            $this->assertSame(1, $e->requestPayload['page'] ?? null);
            $this->assertSame(100, $e->requestPayload['per-page'] ?? null);

            $logContext = $e->contextForLog();
            $this->assertSame('GET', $logContext['request_method']);
            $this->assertStringContainsString('employees-api/summary', (string) $logContext['request_url']);
            $this->assertSame('{"message":"Not Found"}', $logContext['response_body']);
        }
    }

    public function test_afiliado_service_authenticates_via_api_when_enabled(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'auth-token',
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
                            'expedition_date' => '2010-11-12',
                            'mobile_phone_number' => '3005284696',
                            'email' => 'luisa@example.com',
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

        $result = app(AfiliadoService::class)->authenticateAndGetAfiliado(
            'CC',
            '1035228093',
            '2010-11-12'
        );

        $this->assertNotNull($result);
        $this->assertSame('CC', $result['tipo_documento']);
        $this->assertSame('1035228093', $result['documento']);
        $this->assertSame('LUISA', $result['nombres']);
        $this->assertArrayHasKey('convenios', $result);
    }

    public function test_afiliado_service_returns_not_found_when_api_filters_exclude_credentials(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'auth-token',
                'expireIn' => (string) (time() + 3600),
            ]),
            'https://api.prosanet.test/index.php*' => Http::response([
                'items' => [],
                'pagination' => ['page' => 1, 'pageSize' => 10, 'totalCount' => 0, 'pageCount' => 0],
            ]),
        ]);

        $result = app(AfiliadoService::class)->authenticateAndGetAfiliadoDetailed(
            'CC',
            '1035228093',
            '2010-11-12'
        );

        $this->assertSame('affiliate_not_found', $result['status']);
        $this->assertNull($result['afiliado']);
        Http::assertSentCount(2);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'employees-api/detail')
                && str_contains($request->url(), 'expedition_date=2010-11-12');
        });
    }

    public function test_prosanet_api_fetch_all_summary_items_paginates_until_last_page(): void
    {
        Http::fake([
            'https://api.prosanet.test/index.php?r=erpuser/login' => Http::response([
                'access_token' => 'summary-token',
                'expireIn' => (string) (time() + 3600),
            ]),
            'https://api.prosanet.test/index.php?r=employees-api/summary*' => function (\Illuminate\Http\Client\Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                if ($page === 1) {
                    return Http::response([
                        'items' => [
                            [
                                'document_type_label' => 'CC',
                                'document_number' => '111',
                                'first_name' => 'ONE',
                                'last_name' => 'USER',
                                'status' => 'Activo',
                            ],
                        ],
                        'pagination' => [
                            'page' => 1,
                            'pageSize' => 100,
                            'totalCount' => 2,
                            'pageCount' => 2,
                        ],
                    ]);
                }

                return Http::response([
                    'items' => [
                        [
                            'document_type_label' => 'CC',
                            'document_number' => '222',
                            'first_name' => 'TWO',
                            'last_name' => 'USER',
                            'status' => 'Activo',
                        ],
                    ],
                    'pagination' => [
                        'page' => 2,
                        'pageSize' => 100,
                        'totalCount' => 2,
                        'pageCount' => 2,
                    ],
                ]);
            },
        ]);

        $items = app(ProSanetApiService::class)->fetchAllSummaryItems();

        $this->assertCount(2, $items);
        Http::assertSentCount(3);
    }

    public function test_is_data_source_available_when_api_enabled_without_excel(): void
    {
        config(['services.prosanet.enabled' => true]);

        $service = app(AfiliadoService::class);

        $this->assertTrue($service->isDataSourceAvailable());
    }

    public function test_resolve_document_type_id_uses_prosanet_erp_catalog(): void
    {
        $service = app(ProSanetApiService::class);

        $expected = [
            'CC' => 1,
            'CE' => 2,
            'TI' => 3,
            'NUIP' => 4,
            'PE' => 5,
            'PT' => 6,
        ];

        foreach ($expected as $label => $id) {
            $this->assertSame($id, $service->resolveDocumentTypeId($label), "Failed for {$label}");
            $this->assertSame($id, $service->resolveDocumentTypeId(strtolower($label)), "Failed for lowercase {$label}");
        }

        $this->assertNull($service->resolveDocumentTypeId('RC'));
        $this->assertNull($service->resolveDocumentTypeId('PA'));
        $this->assertNull($service->resolveDocumentTypeId('XX'));
    }
}
