<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WellnessDeliveryListEntry;
use App\Models\WellnessDeliveryRequest;
use App\Models\WellnessDeliveryType;
use App\Services\AfiliadoService;
use App\Services\WellnessDeliveryListService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class WellnessDeliveryListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WellnessDeliveryType $halloween;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->withoutMiddleware([
            \App\Http\Middleware\AuthenticateWithApiToken::class,
            \App\Http\Middleware\OptionalAuthenticateWithApiToken::class,
            \App\Http\Middleware\EnsureApiTokenIsValid::class,
            \App\Http\Middleware\CheckPermission::class,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00', 'America/Bogota'));

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['wellness_delivery.view', 'wellness_delivery.manage']);
        $this->actingAs($this->user);

        $this->halloween = WellnessDeliveryType::factory()
            ->entregaListado()
            ->vigente('2026-10-30', '2026-11-01')
            ->create(['nombre' => 'Dulces Halloween']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<list<string|int|null>>  $rows
     */
    private function makeExcel(array $rows, string $name = 'BENEFICIARIOS.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'listado_test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function seedHalloweenList(): void
    {
        $this->postJson("/api/wellness-delivery-types/{$this->halloween->id}/listado", [
            'file' => $this->makeExcel([
                ['Identificación', 'NOMBRE AFILIADO', 'HOSPITAL', 'Nombres y Apellidos Ben.', 'Parentesco', 'TOTAL A ENTREGAR POR AFILIADO'],
                [4053599, 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', 'ROSSYEL', 'HIJO', 3],
                [4053599, 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', 'ROSSYO', 'HIJO', 3],
                [4053599, 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', 'SAMUEL', 'HIJO', 3],
                [8103936, 'GIRALDO MORENO JUAN CAMILO', 'HLM - GRUPO 1', 'EMMA SOPHIA GIRALDO', 'HIJO', 1],
            ]),
        ])->assertCreated();
    }

    private function mockAfiliados(?array $afiliado): void
    {
        $this->mock(AfiliadoService::class, function ($mock) use ($afiliado) {
            $mock->shouldReceive('isFileAvailable')->andReturn(true);
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')->andReturn($afiliado);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupHalloween(string $documento): array
    {
        $response = $this->getJson('/api/wellness-delivery-requests/affiliate-lookup?documento='.$documento)->assertOk();

        return collect($response->json('data.tipos_activos'))->firstWhere('id', $this->halloween->id);
    }

    /**
     * @return list<string>
     */
    private function clavesFor(string $documento): array
    {
        return WellnessDeliveryListEntry::where('documento_afiliado', $documento)->orderBy('id')->pluck('clave')->all();
    }

    private function deliver(string $documento, array $claves): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/wellness-delivery-requests/list-delivery', [
            'wellness_delivery_type_id' => $this->halloween->id,
            'documento_afiliado' => $documento,
            'claves' => $claves,
            'firma' => 'data:image/png;base64,AAAA',
        ]);
    }

    public function test_template_download_contains_standard_headers(): void
    {
        $response = $this->get('/api/wellness-delivery-types/listado/plantilla');

        $response->assertOk();
        $this->assertStringContainsString('Plantilla_Listado_Entregas_Bienestar.xlsx', $response->headers->get('content-disposition'));

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->getFile()->getPathname())->getSheet(0);
        $this->assertSame('DOCUMENTO AFILIADO *', $sheet->getCell('A1')->getValue());
        $this->assertSame('CANTIDAD', $sheet->getCell('G1')->getValue());
    }

    public function test_upload_accepts_beneficiaries_file_with_alias_headers(): void
    {
        $response = $this->postJson("/api/wellness-delivery-types/{$this->halloween->id}/listado", [
            'file' => $this->makeExcel([
                ['Identificación', 'NOMBRE AFILIADO', 'HOSPITAL', 'Nombres y Apellidos Ben.', 'Parentesco', 'TOTAL A ENTREGAR POR AFILIADO'],
                [4053599, 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', 'ROSSYEL ', 'HIJO', 2],
                [4053599, 'NOGUERA VILLARROEL JUERGEN', 'HLM - GRUPO 1', 'ROSSYO', 'HIJO', 2],
                ['8.103.936', 'GIRALDO MORENO JUAN CAMILO', 'HLM - GRUPO 1', 'EMMA SOPHIA GIRALDO', 'HIJO', 1],
                [null, null, null, null, null, null],
            ]),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.listado.afiliados', 2)
            ->assertJsonPath('data.listado.filas', 3)
            ->assertJsonPath('data.listado.cupos', 3)
            ->assertJsonPath('data.listado.archivo', 'BENEFICIARIOS.xlsx')
            ->assertJsonPath('data.columnas_ignoradas', ['TOTAL A ENTREGAR POR AFILIADO']);

        $this->assertDatabaseHas('wellness_delivery_list_entries', [
            'wellness_delivery_type_id' => $this->halloween->id,
            'documento_afiliado' => '8103936',
            'beneficiario' => 'EMMA SOPHIA GIRALDO',
        ]);
        $this->assertSame(['4053599|rossyel', '4053599|rossyo'], $this->clavesFor('4053599'));
    }

    public function test_upload_uses_cantidad_column_and_affiliate_rows_without_beneficiary(): void
    {
        $this->postJson("/api/wellness-delivery-types/{$this->halloween->id}/listado", [
            'file' => $this->makeExcel([
                ['DOCUMENTO AFILIADO *', 'NOMBRE AFILIADO', 'HOSPITAL', 'NOMBRE BENEFICIARIO', 'PARENTESCO', 'EDAD', 'CANTIDAD'],
                ['111', 'ANA', 'HSV', null, null, null, 3],
                ['111', 'ANA', 'HSV', null, null, null, null],
            ]),
        ])->assertCreated()->assertJsonPath('data.listado.cupos', 4);

        $this->assertSame(['111|afiliado', '111|afiliado#2'], $this->clavesFor('111'));
    }

    public function test_upload_with_errors_is_rejected_and_keeps_previous_list(): void
    {
        $this->seedHalloweenList();

        $response = $this->postJson("/api/wellness-delivery-types/{$this->halloween->id}/listado", [
            'file' => $this->makeExcel([
                ['DOCUMENTO AFILIADO', 'NOMBRE BENEFICIARIO', 'CANTIDAD'],
                [null, 'SIN DOCUMENTO', 1],
                ['222', 'CANTIDAD MALA', 'dos'],
            ]),
        ]);

        $response->assertStatus(422)->assertJsonPath('total_errores', 2);
        $this->assertStringContainsString('Fila 2', $response->json('errors.0'));
        $this->assertStringContainsString('Fila 3', $response->json('errors.1'));
        $this->assertSame(4, WellnessDeliveryListEntry::where('wellness_delivery_type_id', $this->halloween->id)->count());
    }

    public function test_upload_without_document_column_is_rejected(): void
    {
        $this->postJson("/api/wellness-delivery-types/{$this->halloween->id}/listado", [
            'file' => $this->makeExcel([['NOMBRE', 'EDAD'], ['ANA', 3]]),
        ])->assertStatus(422);
    }

    public function test_upload_requires_manage_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('wellness_delivery.view');
        $this->actingAs($viewer);

        $this->postJson("/api/wellness-delivery-types/{$this->halloween->id}/listado", [
            'file' => $this->makeExcel([['DOCUMENTO AFILIADO'], ['111']]),
        ])->assertForbidden();
    }

    public function test_lookup_returns_quota_and_beneficiaries_for_list_campaign(): void
    {
        $this->seedHalloweenList();
        $this->mockAfiliados(['documento' => '4053599', 'nombre_completo' => 'JUERGEN NOGUERA', 'estado' => 'Activo', 'hospital' => 'HLM']);

        $tipo = $this->lookupHalloween('4053599');

        $this->assertTrue($tipo['disponible']);
        $this->assertSame(3, $tipo['listado']['cupo_total']);
        $this->assertSame(3, $tipo['listado']['cupo_disponible']);
        $this->assertCount(3, $tipo['listado']['beneficiarios']);
        $this->assertFalse($tipo['listado']['beneficiarios'][0]['entregado']);
    }

    public function test_lookup_marks_list_campaign_unavailable_when_not_in_list(): void
    {
        $this->seedHalloweenList();
        $this->mockAfiliados(['documento' => '999', 'nombre_completo' => 'OTRA PERSONA', 'estado' => 'Activo']);

        $response = $this->getJson('/api/wellness-delivery-requests/affiliate-lookup?documento=999')->assertOk();
        $tipo = collect($response->json('data.tipos_activos'))->firstWhere('id', $this->halloween->id);

        $this->assertFalse($tipo['disponible']);
        $this->assertNull($tipo['listado']);
        $this->assertSame('El afiliado no está en el listado de esta campaña.', $tipo['motivo_no_disponible']);
        $this->assertFalse($response->json('data.puede_registrar_otro_tipo'));
        $this->assertNotEmpty($response->json('data.mensaje_bloqueo'));
    }

    public function test_lookup_uses_list_data_when_affiliate_is_missing_from_affiliates_base(): void
    {
        $this->seedHalloweenList();
        $this->mockAfiliados(null);

        $response = $this->getJson('/api/wellness-delivery-requests/affiliate-lookup?documento=8103936');

        $response->assertOk()
            ->assertJsonPath('data.nombre_afiliado', 'GIRALDO MORENO JUAN CAMILO')
            ->assertJsonPath('data.puede_registrar_otro_tipo', true);
    }

    public function test_lookup_blocks_inactive_affiliate_even_if_in_list(): void
    {
        $this->seedHalloweenList();
        $this->mockAfiliados(['documento' => '4053599', 'nombre_completo' => 'JUERGEN', 'estado' => 'Retirado']);

        $this->getJson('/api/wellness-delivery-requests/affiliate-lookup?documento=4053599')->assertForbidden();
    }

    public function test_partial_deliveries_until_quota_is_exhausted(): void
    {
        $this->seedHalloweenList();
        $this->mockAfiliados(['documento' => '4053599', 'nombre_completo' => 'JUERGEN', 'estado' => 'Activo']);
        $claves = $this->clavesFor('4053599');

        $this->deliver('4053599', [$claves[0], $claves[1]])
            ->assertCreated()
            ->assertJsonPath('data.cantidad_entregada', 2)
            ->assertJsonPath('data.cupo_disponible', 1)
            ->assertJsonPath('data.estado', 'entregado');

        $tipo = $this->lookupHalloween('4053599');
        $this->assertSame(1, $tipo['listado']['cupo_disponible']);
        $this->assertSame([true, true, false], array_column($tipo['listado']['beneficiarios'], 'entregado'));

        $this->deliver('4053599', [$claves[2]])->assertCreated()->assertJsonPath('data.cupo_disponible', 0);

        $tipo = $this->lookupHalloween('4053599');
        $this->assertFalse($tipo['disponible']);
        $this->assertStringContainsString('3 de 3', $tipo['motivo_no_disponible']);

        $request = WellnessDeliveryRequest::latest('id')->first();
        $this->assertSame($this->user->id, $request->entregado_por_user_id);
        $this->assertSame('NOGUERA VILLARROEL JUERGEN', $request->nombre_afiliado);
        $this->assertSame('SAMUEL', $request->beneficiarios[0]['beneficiario']);
    }

    public function test_same_beneficiary_cannot_receive_twice(): void
    {
        $this->seedHalloweenList();
        $clave = $this->clavesFor('8103936')[0];

        $this->deliver('8103936', [$clave])->assertCreated();
        $this->deliver('8103936', [$clave])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La entrega para EMMA SOPHIA GIRALDO ya fue registrada en esta campaña.');

        $this->assertSame(1, WellnessDeliveryRequest::count());
    }

    public function test_delivery_rejects_unknown_document_and_foreign_beneficiary(): void
    {
        $this->seedHalloweenList();

        $this->deliver('999', ['999|x'])->assertStatus(422);
        $this->deliver('8103936', $this->clavesFor('4053599'))->assertStatus(422);

        $this->assertSame(0, WellnessDeliveryRequest::count());
    }

    public function test_delivery_rejects_campaign_that_is_not_list_mode(): void
    {
        $abierto = WellnessDeliveryType::factory()->create(['modo_acceso' => 'abierto']);

        $this->postJson('/api/wellness-delivery-requests/list-delivery', [
            'wellness_delivery_type_id' => $abierto->id,
            'documento_afiliado' => '111',
            'claves' => ['111|'],
            'firma' => 'firma',
        ])->assertStatus(422);
    }

    public function test_cancelled_delivery_frees_quota(): void
    {
        $this->seedHalloweenList();
        $clave = $this->clavesFor('8103936')[0];

        $id = $this->deliver('8103936', [$clave])->assertCreated()->json('data.id');
        WellnessDeliveryRequest::find($id)->update(['estado' => 'cancelado']);

        $this->deliver('8103936', [$clave])->assertCreated();
    }

    public function test_reupload_keeps_deliveries_already_registered(): void
    {
        $this->seedHalloweenList();
        $this->mockAfiliados(['documento' => '8103936', 'nombre_completo' => 'JUAN', 'estado' => 'Activo']);
        $this->deliver('8103936', $this->clavesFor('8103936'))->assertCreated();

        $this->seedHalloweenList();

        $this->assertFalse($this->lookupHalloween('8103936')['disponible']);
    }

    public function test_inscription_endpoint_rejects_list_delivery_campaign(): void
    {
        $this->seedHalloweenList();

        $this->postJson('/api/kit-bienestar/request', [
            'wellness_delivery_type_id' => $this->halloween->id,
            'documento_afiliado' => '8103936',
            'nombre_afiliado' => 'JUAN',
            'firma' => 'firma',
        ])->assertStatus(422);

        $this->assertSame(0, WellnessDeliveryRequest::count());
    }

    public function test_campaign_can_be_created_in_list_delivery_mode_and_exposes_summary(): void
    {
        $id = $this->postJson('/api/wellness-delivery-types', [
            'nombre' => 'Dulces Halloween 2027',
            'modo_acceso' => 'entrega_listado',
            'fecha_desde' => '2027-10-30',
            'fecha_hasta' => '2027-10-31',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/wellness-delivery-types/{$id}")
            ->assertOk()
            ->assertJsonPath('data.modo_acceso', 'entrega_listado')
            ->assertJsonPath('data.listado.cupos', 0);
    }

    public function test_service_normalizes_documents(): void
    {
        $service = app(WellnessDeliveryListService::class);

        $this->assertSame('1234567', $service->normalizeDocumento(' 1.234.567 '));
        $this->assertSame('AB123', $service->normalizeDocumento('ab-123'));
    }
}
