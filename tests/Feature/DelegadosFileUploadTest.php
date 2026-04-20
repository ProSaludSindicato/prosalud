<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DelegadosFileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
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
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function createDelegadosXlsxUpload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');

        $path = storage_path('framework/testing/delegados-upload-'.Str::uuid().'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent('DELEGADOS.xlsx', $content);
    }

    public function test_upload_accepts_valid_delegados_excel(): void
    {
        Storage::fake('prosalud-private');

        $file = $this->createDelegadosXlsxUpload([
            ['NOMBRE Y APELLIDOS', 'CÉDULA', 'SEDE', 'ESTADO BD 1', 'PROCESO', 'ESTADO BD 2'],
            ['María López', '1001159090', 'Central', 'Activo', 'N', 'Ok'],
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('prosalud-private')->assertExists('data/DELEGADOS.xlsx');
    }

    public function test_upload_accepts_reordered_columns_extra_column_and_without_estado_bd(): void
    {
        Storage::fake('prosalud-private');

        $file = $this->createDelegadosXlsxUpload([
            ['NOTAS', 'PROCESO', 'CEDULA', 'NOMBRE Y APELLIDOS', 'SEDE'],
            ['obs', 'P1', '2002003000', 'Juan Pérez', 'Norte'],
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_upload_returns_422_when_headers_are_wrong(): void
    {
        Storage::fake('prosalud-private');

        $file = $this->createDelegadosXlsxUpload([
            ['Nombre', 'Doc', 'SEDE', 'ESTADO BD 1', 'PROCESO', 'ESTADO BD 2'],
            ['María López', '1001159090', 'Central', 'Activo', 'N', 'Ok'],
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_EXCEL_FORMAT');
    }

    public function test_upload_returns_422_when_only_header_row_no_candidates(): void
    {
        Storage::fake('prosalud-private');

        $file = $this->createDelegadosXlsxUpload([
            ['NOMBRE Y APELLIDOS', 'CEDULA', 'SEDE', 'ESTADO BD 1', 'PROCESO', 'ESTADO BD 2'],
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_EXCEL_FORMAT');
    }

    public function test_upload_returns_422_when_missing_required_header(): void
    {
        Storage::fake('prosalud-private');

        $file = $this->createDelegadosXlsxUpload([
            ['NOMBRE Y APELLIDOS', 'CEDULA', 'SEDE'],
            ['María López', '1001159090', 'Central'],
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_EXCEL_FORMAT');
    }

    public function test_upload_returns_422_when_duplicate_known_headers(): void
    {
        Storage::fake('prosalud-private');

        $file = $this->createDelegadosXlsxUpload([
            ['NOMBRE Y APELLIDOS', 'CEDULA', 'SEDE', 'PROCESO', 'PROCESO'],
            ['María López', '1001159090', 'Central', 'N', 'dup'],
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('delegados_files.manage');

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_EXCEL_FORMAT');
    }

    public function test_upload_returns_403_without_permission(): void
    {
        Storage::fake('prosalud-private');

        $file = $this->createDelegadosXlsxUpload([
            ['NOMBRE Y APELLIDOS', 'CEDULA', 'SEDE', 'ESTADO BD 1', 'PROCESO', 'ESTADO BD 2'],
            ['María López', '1001159090', 'Central', 'Activo', 'N', 'Ok'],
        ]);

        $user = User::factory()->create();

        $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post('/api/delegados-file/upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
