<?php

namespace Tests\Feature;

use App\Jobs\GenerateConvenioJob;
use App\Models\ApiToken;
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

class ImportBulkConveniosTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_import_bulk_uses_request_send_email_toggle_not_excel_column(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Bus::fake([GenerateConvenioJob::class]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.manage');

        $file = $this->createConveniosXlsxUpload([
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
                'Email',
                'Enviar Email',
            ],
            [
                '1234567890',
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
                'afiliado@example.com',
                'No',
            ],
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/convenios-manual/import-bulk', [
                'file' => $file,
                'send_email' => true,
            ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.exitosos', 1)
            ->assertJsonPath('data.send_email', true);

        Bus::assertDispatched(GenerateConvenioJob::class, function (GenerateConvenioJob $job): bool {
            return $job->sendEmail === true
                && $job->email === 'afiliado@example.com';
        });
    }

    public function test_import_bulk_defaults_send_email_to_true(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Bus::fake([GenerateConvenioJob::class]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.manage');

        $file = $this->createConveniosXlsxUpload([
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
                '9876543210',
                'Lopez',
                'Maria',
                '1992-05-10',
                'Medellin',
                'TEST CONVENIO',
                'Medellin',
                'BELLO',
                '2026-01-01',
                'Calle 2',
                '3009876543',
                'Compensacion basica de prueba.',
            ],
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/convenios-manual/import-bulk', [
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('data.send_email', true);

        Bus::assertDispatched(GenerateConvenioJob::class, fn (GenerateConvenioJob $job): bool => $job->sendEmail === true);
    }

    public function test_import_bulk_rejects_template_without_data_rows(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Bus::fake([GenerateConvenioJob::class]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.manage');

        $file = $this->createConveniosXlsxUpload([
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
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/convenios-manual/import-bulk', [
                'file' => $file,
                'send_email' => true,
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'message' => 'El archivo Excel no contiene filas de datos. Complete al menos una fila debajo de los encabezados (a partir de la fila 2) e intente nuevamente.',
            ]);

        Bus::assertNothingDispatched();
    }

    public function test_import_bulk_reads_excel_when_default_disk_is_prosalud_private(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Bus::fake([GenerateConvenioJob::class]);
        Storage::fake('prosalud-private');
        config(['filesystems.default' => 'prosalud-private']);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.manage');

        $file = $this->createConveniosXlsxUpload([
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
                '5555555555',
                'Gomez',
                'Ana',
                '1991-03-15',
                'Medellin',
                'TEST CONVENIO',
                'Medellin',
                'BELLO',
                '2026-01-01',
                'Calle 3',
                '3005555555',
                'Compensacion basica de prueba.',
            ],
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/convenios-manual/import-bulk', [
                'file' => $file,
                'send_email' => true,
            ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.exitosos', 1);

        Bus::assertDispatched(GenerateConvenioJob::class);
    }
}
