<?php

namespace Tests\Feature;

use App\Jobs\GenerateConvenioJob;
use App\Jobs\ProcessConvenioPdfZipJob;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

class ConvenioImportDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config([
            'convenios.storage_disk' => 'prosalud-private',
            'convenio_signing.enabled' => true,
            'convenios.delivery_mode' => 'production',
        ]);

        if (! config('convenios.duplicate_import_check_enabled')) {
            $this->markTestSkipped('Duplicate import check is temporarily disabled.');
        }
    }

    public function test_import_pdf_zip_asks_confirmation_when_duplicate_exists(): void
    {
        Bus::fake([ProcessConvenioPdfZipJob::class]);
        [, $token] = $this->authenticatedManageUser();

        ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'documento' => '71226924',
            'sede' => 'BELLO',
            'nombre_convenio' => 'BELLO',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $this->createConvenioZipUpload([
                    'BELLO - QUILINDO LOAIZA WVEYMAR - 71226924.pdf' => '%PDF-1.4 valid pdf one',
                ]),
                'send_email' => true,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'duplicate_convenios')
            ->assertJsonPath('data.duplicates.0.documento', '71226924')
            ->assertJsonPath('data.duplicates.0.sede', 'BELLO')
            ->assertJsonPath('data.duplicates.0.can_invalidate', true)
            ->assertJsonPath('data.duplicates.0.recommended_action', 'invalidate_and_proceed');

        Bus::assertNothingDispatched();
        $this->assertSame(1, ConvenioEmailTracking::query()->count());
    }

    public function test_import_pdf_zip_invalidates_previous_when_confirmed(): void
    {
        Bus::fake([ProcessConvenioPdfZipJob::class]);
        [, $token] = $this->authenticatedManageUser();

        $existing = ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'documento' => '71226924',
            'sede' => 'BELLO',
            'nombre_convenio' => 'BELLO',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $this->createConvenioZipUpload([
                    'BELLO - QUILINDO LOAIZA WVEYMAR - 71226924.pdf' => '%PDF-1.4 valid pdf one',
                ]),
                'send_email' => true,
                'confirm_duplicates' => true,
                'duplicate_actions' => json_encode([
                    [
                        'documento' => '71226924',
                        'sede' => 'BELLO',
                        'action' => 'invalidate_and_proceed',
                        'invalidation_reason' => 'Este convenio fue reemplazado por uno nuevo. Use el enlace de firma más reciente.',
                    ],
                ]),
            ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.validos', 1);

        $existing->refresh();
        $this->assertTrue($existing->isInvalidated());
        $this->assertSame(
            'Este convenio fue reemplazado por uno nuevo. Use el enlace de firma más reciente.',
            $existing->motivo_rechazo,
        );

        Bus::assertDispatched(ProcessConvenioPdfZipJob::class, function (ProcessConvenioPdfZipJob $job): bool {
            return count($job->validEntries) === 1
                && $job->validEntries[0]['documento'] === '71226924';
        });
    }

    public function test_import_pdf_zip_uses_per_row_invalidation_reason(): void
    {
        Bus::fake([ProcessConvenioPdfZipJob::class]);
        [, $token] = $this->authenticatedManageUser();

        $existing = ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'documento' => '71226924',
            'sede' => 'BELLO',
            'nombre_convenio' => 'BELLO',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $this->createConvenioZipUpload([
                    'BELLO - QUILINDO LOAIZA WVEYMAR - 71226924.pdf' => '%PDF-1.4 valid pdf one',
                ]),
                'send_email' => true,
                'confirm_duplicates' => true,
                'duplicate_actions' => json_encode([
                    [
                        'documento' => '71226924',
                        'sede' => 'BELLO',
                        'action' => 'invalidate_and_proceed',
                        'invalidation_reason' => 'Motivo particular para este afiliado.',
                    ],
                ]),
            ], ['Accept' => 'application/json']);

        $response->assertAccepted();

        $existing->refresh();
        $this->assertSame('Motivo particular para este afiliado.', $existing->motivo_rechazo);
    }

    public function test_import_pdf_zip_skips_duplicate_when_requested(): void
    {
        Bus::fake([ProcessConvenioPdfZipJob::class]);
        [, $token] = $this->authenticatedManageUser();

        ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'documento' => '71226924',
            'sede' => 'BELLO',
            'nombre_convenio' => 'BELLO',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $this->createConvenioZipUpload([
                    'BELLO - QUILINDO LOAIZA WVEYMAR - 71226924.pdf' => '%PDF-1.4 valid pdf one',
                    'BELLO - OTRA PERSONA - 11111111.pdf' => '%PDF-1.4 valid pdf two',
                ]),
                'send_email' => true,
                'confirm_duplicates' => true,
                'duplicate_actions' => json_encode([
                    [
                        'documento' => '71226924',
                        'sede' => 'BELLO',
                        'action' => 'skip',
                    ],
                ]),
            ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('data.validos', 1)
            ->assertJsonPath('data.omitidos', 1);

        Bus::assertDispatched(ProcessConvenioPdfZipJob::class, function (ProcessConvenioPdfZipJob $job): bool {
            return count($job->validEntries) === 1
                && $job->validEntries[0]['documento'] === '11111111';
        });
    }

    public function test_import_pdf_zip_rejects_invalidating_signed_duplicate(): void
    {
        Bus::fake([ProcessConvenioPdfZipJob::class]);
        [, $token] = $this->authenticatedManageUser();

        ConvenioEmailTracking::factory()->firmadoAfiliado()->create([
            'documento' => '71226924',
            'sede' => 'BELLO',
            'nombre_convenio' => 'BELLO',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/convenios-manual/import-pdf-zip', [
                'file' => $this->createConvenioZipUpload([
                    'BELLO - QUILINDO LOAIZA WVEYMAR - 71226924.pdf' => '%PDF-1.4 valid pdf one',
                ]),
                'send_email' => true,
                'confirm_duplicates' => true,
                'duplicate_actions' => json_encode([
                    [
                        'documento' => '71226924',
                        'sede' => 'BELLO',
                        'action' => 'invalidate_and_proceed',
                    ],
                ]),
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);

        Bus::assertNothingDispatched();
        $this->assertSame(
            ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            ConvenioEmailTracking::query()->first()?->signing_estado,
        );
    }

    public function test_import_bulk_excel_asks_confirmation_when_duplicate_exists(): void
    {
        Bus::fake([GenerateConvenioJob::class]);
        [, $token] = $this->authenticatedManageUser();

        ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'documento' => '1234567890',
            'sede' => 'BELLO',
            'nombre_convenio' => 'TEST CONVENIO',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/convenios-manual/import-bulk', [
                'file' => $this->createConveniosXlsxUpload($this->validExcelRows('1234567890')),
                'send_email' => true,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_convenios')
            ->assertJsonPath('data.duplicates.0.documento', '1234567890')
            ->assertJsonPath('data.duplicates.0.sede', 'BELLO');

        Bus::assertNothingDispatched();
    }

    public function test_import_bulk_excel_invalidates_previous_when_confirmed(): void
    {
        Bus::fake([GenerateConvenioJob::class]);
        [, $token] = $this->authenticatedManageUser();

        $existing = ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'documento' => '1234567890',
            'sede' => 'BELLO',
            'nombre_convenio' => 'TEST CONVENIO',
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $token)
            ->post('/api/convenios-manual/import-bulk', [
                'file' => $this->createConveniosXlsxUpload($this->validExcelRows('1234567890')),
                'send_email' => true,
                'confirm_duplicates' => true,
                'duplicate_actions' => json_encode([
                    [
                        'documento' => '1234567890',
                        'sede' => 'BELLO',
                        'action' => 'invalidate_and_proceed',
                        'invalidation_reason' => 'Este convenio fue reemplazado por uno nuevo. Use el enlace de firma más reciente.',
                    ],
                ]),
            ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('data.exitosos', 1);

        $existing->refresh();
        $this->assertTrue($existing->isInvalidated());
        Bus::assertDispatched(GenerateConvenioJob::class);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function authenticatedManageUser(): array
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email' => 'admin-duplicates-'.Str::random(8).'@example.com',
        ]);
        $user->givePermissionTo(['document_signing.manage', 'document_signing.view']);

        return [$user, $this->apiCookieForUser($user)];
    }

    private function apiCookieForUser(User $user): string
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function createConvenioZipUpload(array $entries): UploadedFile
    {
        $zipPath = storage_path('framework/testing/convenios-'.Str::uuid().'.zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();

        $content = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return UploadedFile::fake()->createWithContent('convenios.zip', $content);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function createConveniosXlsxUpload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');

        $path = storage_path('framework/testing/convenios-import-'.Str::uuid().'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent('convenios.xlsx', $content);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function validExcelRows(string $documento): array
    {
        return [
            [
                'Numero Documento',
                'Apellidos',
                'Nombres',
                'Fecha Nacimiento',
                'Lugar Nacimiento',
                'Proceso',
                'Ciudad',
                'Sede',
                'Fecha Inicio',
                'Direccion',
                'Celular',
                'Compensacion Basica Redactada',
            ],
            [
                $documento,
                'Perez',
                'Juan',
                '1990-01-01',
                'Medellin',
                'TEST CONVENIO',
                'Medellin',
                'BELLO',
                '2026-01-01',
                'Calle 1',
                '3001234567',
                'Compensacion basica de prueba.',
            ],
        ];
    }
}
