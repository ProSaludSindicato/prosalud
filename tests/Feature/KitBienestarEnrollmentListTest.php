<?php

namespace Tests\Feature;

use App\Models\KitBienestarFileVersion;
use App\Models\User;
use App\Models\WellnessDeliveryType;
use App\Services\AfiliadoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class KitBienestarEnrollmentListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WellnessDeliveryType $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->withoutMiddleware([
            \App\Http\Middleware\AuthenticateWithApiToken::class,
            \App\Http\Middleware\OptionalAuthenticateWithApiToken::class,
            \App\Http\Middleware\EnsureApiTokenIsValid::class,
            \App\Http\Middleware\CheckPermission::class,
            ThrottleRequests::class,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'America/Bogota'));
        Storage::fake('prosalud-private');

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['wellness_delivery.view', 'wellness_delivery.manage']);
        $this->actingAs($this->user);

        $this->campaign = WellnessDeliveryType::factory()
            ->inscripcionListado()
            ->vigente('2026-11-30', '2026-12-31')
            ->create(['nombre' => 'Fiesta de fin de año']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function makeExcel(array $rows, string $name = 'LISTADO.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'inscripcion_test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function upload(array $rows): TestResponse
    {
        return $this->postJson('/api/wellness-delivery-requests/file/upload', ['file' => $this->makeExcel($rows)]);
    }

    private function authenticate(string $documento, string $fecha = '01/01/00'): TestResponse
    {
        return $this->postJson('/api/kit-bienestar/authenticate', [
            'tipo_documento' => 'CC',
            'documento' => $documento,
            'fecha_expedicion' => $fecha,
            'wellness_delivery_type_id' => $this->campaign->id,
        ]);
    }

    public function test_template_has_list_and_instructions_sheets(): void
    {
        $response = $this->get('/api/wellness-delivery-requests/file/template');

        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);

        $this->assertSame(['Listado', 'Instructivo'], $spreadsheet->getSheetNames());
        $headers = $spreadsheet->getSheetByName('Listado')->rangeToArray('A1:G1')[0];
        $this->assertSame(['DOCUMENTO *', 'NOMBRE', 'HOSPITAL', 'FECHA EXPEDICION', 'NOMBRE BENEFICIARIO', 'PARENTESCO', 'EDAD'], $headers);
        $this->assertStringContainsString('INSTRUCTIVO', (string) $spreadsheet->getSheetByName('Instructivo')->getCell('A1')->getValue());
    }

    public function test_template_uploaded_with_rows_is_accepted(): void
    {
        $response = $this->upload([
            ['DOCUMENTO *', 'NOMBRE', 'HOSPITAL', 'FECHA EXPEDICION', 'NOMBRE BENEFICIARIO', 'PARENTESCO', 'EDAD'],
            ['8.103.936', 'GIRALDO MORENO JUAN CAMILO', 'HLM', null, null, null, null],
            ['4053599', 'NOGUERA JUERGEN', 'HLM', '15/03/1990', 'ROSSYEL', 'HIJO', '5'],
            ['4053599', 'NOGUERA JUERGEN', 'HLM', '15/03/1990', 'ROSSYO', 'HIJO', '3'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.resumen.filas', 3)
            ->assertJsonPath('data.resumen.personas', 2)
            ->assertJsonPath('data.resumen.formato_antiguo', false);
        $this->assertSame(1, KitBienestarFileVersion::where('is_active', true)->count());
    }

    public function test_guest_list_with_only_document_and_name_authenticates_by_document(): void
    {
        $this->upload([
            ['Cédula', 'Nombre invitado', 'Mesa'],
            [8103936, 'GIRALDO MORENO JUAN CAMILO', '4'],
        ])->assertCreated()->assertJsonPath('data.resumen.columnas_ignoradas', ['Mesa']);

        $this->authenticate('8103936', '20/07/05')
            ->assertOk()
            ->assertJsonPath('data.afiliado.nombre', 'GIRALDO MORENO JUAN CAMILO')
            ->assertJsonPath('data.beneficiarios', [])
            ->assertJsonPath('data.wellness_delivery_type_id', $this->campaign->id);

        $this->authenticate('999999')->assertNotFound();
    }

    public function test_row_with_issue_date_requires_matching_date(): void
    {
        $this->upload([
            ['DOCUMENTO', 'NOMBRE', 'FECHA EXPEDICION'],
            ['4053599', 'NOGUERA JUERGEN', '15/03/1990'],
        ])->assertCreated();

        $this->authenticate('4053599', '16/03/90')->assertNotFound();
        $this->authenticate('4053599', '15/03/90')
            ->assertOk()
            ->assertJsonPath('data.afiliado.fecha_expedicion', '15/03/90');
    }

    public function test_beneficiary_rows_are_grouped_by_document(): void
    {
        $this->upload([
            ['DOCUMENTO', 'NOMBRE', 'HOSPITAL', 'NOMBRE BENEFICIARIO', 'PARENTESCO', 'EDAD'],
            ['4053599', 'NOGUERA JUERGEN', 'HLM', 'ROSSYEL', 'HIJO', '5'],
            ['4053599', 'NOGUERA JUERGEN', 'HLM', 'ROSSYO', 'HIJO', '3'],
            ['7000001', 'SOLO UN HIJO', 'HPTU', 'ANA', 'HIJA', '7'],
        ])->assertCreated();

        $this->authenticate('4053599')
            ->assertOk()
            ->assertJsonPath('data.afiliado.hospital', 'HLM')
            ->assertJsonCount(2, 'data.beneficiarios')
            ->assertJsonPath('data.beneficiarios.1.beneficiario', 'ROSSYO');

        $this->authenticate('7000001')
            ->assertOk()
            ->assertJsonPath('data.beneficiario', 'ANA')
            ->assertJsonPath('data.nombre', 'SOLO UN HIJO');
    }

    public function test_old_style_headers_are_recognized_by_keywords(): void
    {
        $this->upload([
            ['CC', 'NOMBRE', 'HOSPITAL', 'FECHA EXPEDICIÓN CC', 'NOMBRE DEL BENEFICIARIO', 'PARENTESCO', 'EDAD BEN.'],
            ['4053599', 'NOGUERA JUERGEN', 'HLM', '15/03/1990', 'ROSSYEL', 'HIJO', '5'],
        ])->assertCreated()->assertJsonPath('data.resumen.formato_antiguo', false)->assertJsonPath('data.resumen.columnas_ignoradas', []);

        $this->authenticate('4053599', '15/03/90')
            ->assertOk()
            ->assertJsonPath('data.beneficiario', 'ROSSYEL')
            ->assertJsonPath('data.edad', '5');
        $this->authenticate('4053599', '16/03/90')->assertNotFound();
    }

    public function test_file_without_recognizable_headers_uses_positional_layout(): void
    {
        $this->upload([
            ['Identif.', 'Nom', 'Hosp', 'Fecha', 'Ben', 'Par', 'Ed'],
            ['4053599', 'NOGUERA JUERGEN', 'HLM', '15/03/1990', 'ROSSYEL', 'HIJO', '5'],
        ])->assertCreated()->assertJsonPath('data.resumen.formato_antiguo', true);

        $this->authenticate('4053599', '15/03/90')->assertOk()->assertJsonPath('data.beneficiario', 'ROSSYEL');
    }

    public function test_positional_layout_requires_issue_date(): void
    {
        $this->upload([
            ['Identif.', 'Nom', 'Hosp', 'Fecha'],
            ['5000000', 'SIN FECHA', 'HLM', null],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0', 'Fila 2: falta la fecha de expedición (obligatoria en el formato sin encabezados).');
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function uploadToCampaign(WellnessDeliveryType $type, array $rows): TestResponse
    {
        return $this->postJson("/api/wellness-delivery-types/{$type->id}/listado", ['file' => $this->makeExcel($rows)]);
    }

    public function test_campaign_template_for_enrollment_mode_has_instructions_sheet(): void
    {
        $response = $this->get('/api/wellness-delivery-types/listado/plantilla?modo=listado');

        $response->assertOk();
        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['Listado', 'Instructivo'], $spreadsheet->getSheetNames());
        $this->assertSame('DOCUMENTO *', $spreadsheet->getSheet(0)->getCell('A1')->getValue());
    }

    public function test_each_campaign_authenticates_against_its_own_list(): void
    {
        $kits = WellnessDeliveryType::factory()
            ->inscripcionListado()
            ->vigente('2026-11-30', '2026-12-31')
            ->create(['nombre' => 'Kits escolares']);

        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'NOMBRE'],
            ['8103936', 'GIRALDO MORENO JUAN CAMILO'],
        ])->assertCreated()->assertJsonPath('data.listado.afiliados', 1);

        $this->uploadToCampaign($kits, [
            ['DOCUMENTO', 'NOMBRE', 'FECHA EXPEDICION', 'NOMBRE BENEFICIARIO'],
            ['4053599', 'NOGUERA JUERGEN', '15/03/1990', 'ROSSYEL'],
            ['4053599', 'NOGUERA JUERGEN', '15/03/1990', 'ROSSYO'],
        ])->assertCreated()->assertJsonPath('data.listado.filas', 2)->assertJsonPath('data.listado.afiliados', 1);

        $this->authenticate('8103936')->assertOk()->assertJsonPath('data.beneficiarios', []);
        $this->authenticate('4053599', '15/03/90')->assertNotFound();

        $authKits = fn (string $documento, string $fecha) => $this->postJson('/api/kit-bienestar/authenticate', [
            'tipo_documento' => 'CC',
            'documento' => $documento,
            'fecha_expedicion' => $fecha,
            'wellness_delivery_type_id' => $kits->id,
        ]);
        $authKits('4053599', '15/03/90')->assertOk()->assertJsonCount(2, 'data.beneficiarios');
        $authKits('4053599', '01/01/90')->assertNotFound();
        $authKits('8103936', '01/01/00')->assertNotFound();
    }

    public function test_campaign_list_takes_precedence_over_general_file(): void
    {
        $this->upload([
            ['DOCUMENTO', 'NOMBRE'],
            ['1111111', 'SOLO EN ARCHIVO GENERAL'],
        ])->assertCreated();

        $this->authenticate('1111111')->assertOk();

        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'NOMBRE'],
            ['2222222', 'SOLO EN LISTADO DE CAMPAÑA'],
        ])->assertCreated();

        $this->authenticate('1111111')->assertNotFound();
        $this->authenticate('2222222')->assertOk();
    }

    public function test_campaign_list_upload_with_errors_is_rejected(): void
    {
        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'FECHA EXPEDICION'],
            ['123', '99/99/9999'],
        ])->assertUnprocessable()->assertJsonPath('total_errores', 1);

        $this->assertNull($this->campaign->fresh()->listado_cargado_at);
    }

    public function test_campaign_exposes_enrollment_list_summary(): void
    {
        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'NOMBRE'],
            ['8103936', 'A'],
            ['1020304050', 'B'],
        ])->assertCreated();

        $this->getJson("/api/wellness-delivery-types/{$this->campaign->id}")
            ->assertOk()
            ->assertJsonPath('data.listado.afiliados', 2)
            ->assertJsonPath('data.listado.archivo', 'LISTADO.xlsx');
    }

    public function test_inscription_is_rejected_when_document_is_not_in_campaign_list(): void
    {
        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'NOMBRE'],
            ['8103936', 'GIRALDO MORENO JUAN CAMILO'],
        ])->assertCreated();

        $payload = fn (string $documento) => [
            'documento_afiliado' => $documento,
            'nombre_afiliado' => 'Persona',
            'fecha_expedicion' => '01/01/00',
            'beneficiarios' => [],
            'firma' => 'data:image/png;base64,AAAA',
            'wellness_delivery_type_id' => $this->campaign->id,
        ];

        $this->postJson('/api/kit-bienestar/request', $payload('9999999'))->assertUnprocessable();
        $this->postJson('/api/kit-bienestar/request', $payload('8103936'))->assertSuccessful();
    }

    public function test_staff_registration_validates_only_the_document_against_campaign_list(): void
    {
        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'NOMBRE', 'FECHA EXPEDICION'],
            ['4053599', 'NOGUERA JUERGEN', '15/03/1990'],
        ])->assertCreated();

        $payload = fn (string $documento) => [
            'documento_afiliado' => $documento,
            'nombre_afiliado' => 'Persona',
            'fecha_expedicion' => '',
            'beneficiarios' => [],
            'firma' => 'data:image/png;base64,AAAA',
            'wellness_delivery_type_id' => $this->campaign->id,
        ];

        $this->postJson('/api/wellness-delivery-requests', $payload('9999999'))->assertUnprocessable();
        $this->postJson('/api/wellness-delivery-requests', $payload('4053599'))->assertSuccessful();
    }

    public function test_public_inscription_requires_matching_issue_date_when_list_has_it(): void
    {
        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'NOMBRE', 'FECHA EXPEDICION'],
            ['4053599', 'NOGUERA JUERGEN', '15/03/1990'],
        ])->assertCreated();
        auth()->logout();

        $payload = fn (string $fecha) => [
            'documento_afiliado' => '4053599',
            'nombre_afiliado' => 'Persona',
            'fecha_expedicion' => $fecha,
            'beneficiarios' => [],
            'firma' => 'data:image/png;base64,AAAA',
            'wellness_delivery_type_id' => $this->campaign->id,
        ];

        $this->postJson('/api/kit-bienestar/request', $payload('16/03/90'))->assertUnprocessable();
        $this->postJson('/api/kit-bienestar/request', $payload('15/03/90'))->assertSuccessful();
    }

    public function test_affiliate_lookup_marks_campaign_unavailable_when_not_in_its_list(): void
    {
        $this->mock(AfiliadoService::class, function ($mock) {
            $mock->shouldReceive('isFileAvailable')->andReturn(true);
            $mock->shouldReceive('getAfiliadoByDocumentoOnly')->andReturn(['nombre' => 'X', 'estado' => 'Activo']);
        });
        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'NOMBRE', 'FECHA EXPEDICION'],
            ['4053599', 'NOGUERA JUERGEN', '15/03/1990'],
        ])->assertCreated();

        $tipo = fn (string $documento) => collect(
            $this->getJson('/api/wellness-delivery-requests/affiliate-lookup?documento='.$documento)->assertOk()->json('data.tipos_activos')
        )->firstWhere('id', $this->campaign->id);

        $this->assertTrue($tipo('4053599')['disponible']);
        $fuera = $tipo('9999999');
        $this->assertFalse($fuera['disponible']);
        $this->assertSame('La persona no está en el listado de esta campaña.', $fuera['motivo_no_disponible']);
    }

    public function test_campaign_gets_unique_readable_slug_that_survives_renames(): void
    {
        $this->assertSame('fiesta-de-fin-de-ano', $this->campaign->slug);

        $created = $this->postJson('/api/wellness-delivery-types', [
            'nombre' => 'Fiesta de fin de año',
            'modo_acceso' => 'listado',
        ])->assertCreated()->assertJsonPath('data.slug', 'fiesta-de-fin-de-ano-2');

        $id = $created->json('data.id');
        $this->putJson("/api/wellness-delivery-types/{$id}", ['nombre' => 'Fiesta navideña'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'fiesta-de-fin-de-ano-2');
    }

    public function test_slug_is_replaced_only_when_explicitly_requested(): void
    {
        WellnessDeliveryType::factory()->create(['nombre' => 'Encuentro cierre de año']);

        $this->putJson("/api/wellness-delivery-types/{$this->campaign->id}", [
            'nombre' => 'Encuentro cierre de año',
            'actualizar_slug' => true,
        ])->assertOk()->assertJsonPath('data.slug', 'encuentro-cierre-de-ano-2');

        $this->putJson("/api/wellness-delivery-types/{$this->campaign->id}", [
            'nombre' => 'Encuentro cierre de año',
            'actualizar_slug' => true,
        ])->assertOk()->assertJsonPath('data.slug', 'encuentro-cierre-de-ano-2');

        $this->authenticateBySlugCampaignStillWorks();
    }

    private function authenticateBySlugCampaignStillWorks(): void
    {
        $tipo = collect($this->getJson('/api/kit-bienestar/current-type')->assertOk()->json('data'))
            ->firstWhere('id', $this->campaign->id);
        $this->assertSame('encuentro-cierre-de-ano-2', $tipo['slug']);
    }

    public function test_current_type_exposes_slug_for_public_link(): void
    {
        $tipo = collect($this->getJson('/api/kit-bienestar/current-type')->assertOk()->json('data'))
            ->firstWhere('id', $this->campaign->id);

        $this->assertSame('fiesta-de-fin-de-ano', $tipo['slug']);
    }

    public function test_authenticate_requires_campaign_id_when_other_modes_are_also_active(): void
    {
        WellnessDeliveryType::factory()->create(['nombre' => 'Día de la mujer']);
        $this->uploadToCampaign($this->campaign, [
            ['DOCUMENTO', 'NOMBRE'],
            ['8103936', 'GIRALDO MORENO JUAN CAMILO'],
        ])->assertCreated();

        $this->postJson('/api/kit-bienestar/authenticate', [
            'tipo_documento' => 'CC',
            'documento' => '8103936',
            'fecha_expedicion' => '01/01/00',
        ])->assertUnprocessable();

        $this->authenticate('8103936')->assertOk();
    }

    public function test_upload_with_row_errors_is_rejected_and_keeps_previous_version(): void
    {
        $this->upload([
            ['DOCUMENTO', 'NOMBRE'],
            ['4053599', 'NOGUERA JUERGEN'],
        ])->assertCreated();

        $this->upload([
            ['DOCUMENTO', 'NOMBRE', 'FECHA EXPEDICION'],
            [null, 'SIN DOCUMENTO', null],
            ['123', 'FECHA MALA', '31/02/1990'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0', 'Fila 2: falta el documento.')
            ->assertJsonPath('errors.1', 'Fila 3: la fecha de expedición «31/02/1990» no es válida (use dd/mm/aaaa).');

        $this->assertSame(1, KitBienestarFileVersion::count());
        $this->authenticate('4053599')->assertOk();
    }

    public function test_empty_list_is_rejected(): void
    {
        $this->upload([
            ['DOCUMENTO', 'NOMBRE'],
        ])->assertUnprocessable()->assertJsonPath('errors.0', 'El listado no tiene filas con datos.');

        $this->assertSame(0, KitBienestarFileVersion::count());
    }

    public function test_excel_date_cells_are_supported(): void
    {
        $serial = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('1990-03-15'));

        $this->upload([
            ['DOCUMENTO', 'NOMBRE', 'FECHA DE EXPEDICIÓN'],
            ['4053599', 'NOGUERA JUERGEN', $serial],
        ])->assertCreated();

        $this->authenticate('4053599', '15/03/90')->assertOk();
    }
}
