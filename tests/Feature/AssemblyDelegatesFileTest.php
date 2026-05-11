<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Assembly;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AssemblyDelegatesFileTest extends TestCase
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

    public function test_upload_rejects_invalid_structure(): void
    {
        Storage::fake('prosalud-private');

        $user = User::factory()->create();
        $user->givePermissionTo('assembly.questions.manage');

        $assembly = Assembly::query()->create([
            'name' => 'Asamblea test',
            'description' => null,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
        ]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['A', 'B', 'C', 'D'],
            ['1', '2', '3', '4'],
        ]);
        $tempPath = sys_get_temp_dir().'/bad_delegados_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $upload = new UploadedFile($tempPath, 'bad.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))->post("/api/assembly/assemblies/{$assembly->id}/delegates-file", [
            'file' => $upload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'INVALID_DELEGATES_STRUCTURE');

        @unlink($tempPath);
    }

    public function test_upload_accepts_valid_delegates_excel(): void
    {
        Storage::fake('prosalud-private');

        $user = User::factory()->create();
        $user->givePermissionTo('assembly.questions.manage');

        $assembly = Assembly::query()->create([
            'name' => 'Asamblea test',
            'description' => null,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
        ]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['CEDULA', 'NOMBRE Y APELLIDOS', 'SEDE', 'ESTADO BD', 'PROCESO', 'FECHA DE EXPEDICION'],
            ['12345678', 'Persona Prueba', 'BELLO', 'ACTIVO', 'AUXILIAR', '1/14/2002'],
        ]);
        $tempPath = sys_get_temp_dir().'/good_delegados_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $upload = new UploadedFile($tempPath, 'delegados.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))->post("/api/assembly/assemblies/{$assembly->id}/delegates-file", [
            'file' => $upload,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $assembly->refresh();
        $this->assertNotNull($assembly->delegates_file_path);
        $this->assertSame('prosalud-private', $assembly->delegates_file_disk);

        $this->assertDatabaseHas('assembly_delegate_file_versions', [
            'assembly_id' => $assembly->id,
            'row_count' => 1,
        ]);

        @unlink($tempPath);
    }

    public function test_upload_accepts_legacy_four_column_delegates_excel(): void
    {
        Storage::fake('prosalud-private');

        $user = User::factory()->create();
        $user->givePermissionTo('assembly.questions.manage');

        $assembly = Assembly::query()->create([
            'name' => 'Asamblea test legacy',
            'description' => null,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
        ]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['CEDULA', 'NOMBRE Y APELLIDOS', 'ESTADO BD', 'F. EXPEDICIÓN'],
            ['12345678', 'Persona Prueba', 'ACTIVO', '1/Ene/2020'],
        ]);
        $tempPath = sys_get_temp_dir().'/legacy_delegados_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $upload = new UploadedFile($tempPath, 'legacy.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))->post("/api/assembly/assemblies/{$assembly->id}/delegates-file", [
            'file' => $upload,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        @unlink($tempPath);
    }

    public function test_upload_forbidden_without_permission(): void
    {
        Storage::fake('prosalud-private');

        $user = User::factory()->create();

        $assembly = Assembly::query()->create([
            'name' => 'Asamblea test',
            'description' => null,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
        ]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['CEDULA', 'NOMBRE Y APELLIDOS', 'SEDE', 'ESTADO BD', 'PROCESO', 'FECHA DE EXPEDICION'],
            ['12345678', 'Persona Prueba', 'X', 'ACTIVO', 'Y', '1/1/2020'],
        ]);
        $tempPath = sys_get_temp_dir().'/good_delegados_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $upload = new UploadedFile($tempPath, 'delegados.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))->post("/api/assembly/assemblies/{$assembly->id}/delegates-file", [
            'file' => $upload,
        ]);

        $response->assertStatus(403);

        @unlink($tempPath);
    }
}
