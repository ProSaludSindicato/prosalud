<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\AssemblyAttendance;
use App\Services\AssemblyAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssemblyAttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private const MINIMAL_PNG_DATA_URI = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_record_does_not_create_duplicate_when_delegate_already_has_signature_for_assembly(): void
    {
        Storage::fake('prosalud-private');

        $assembly = Assembly::query()->create([
            'name' => 'Asamblea test',
            'description' => null,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
            'allows_reactivation' => true,
        ]);

        $existing = AssemblyAttendance::query()->create([
            'assembly_id' => $assembly->id,
            'document_number' => '1000918728',
            'full_name' => 'DELEGADO PRUEBA',
            'issue_date_normalized' => '2015-06-15',
            'signature_path' => 'assembly-signatures/1000918728/existing.png',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'First',
            'authenticated_at' => now()->subHours(2),
        ]);

        $request = Request::create('/api/activos/search-hospital', 'POST');
        $request->server->set('REMOTE_ADDR', '10.0.0.5');
        $request->headers->set('User-Agent', 'Second-Auth');

        /** @var AssemblyAttendanceService $service */
        $service = app(AssemblyAttendanceService::class);

        $result = $service->record(
            '1000918728',
            'DELEGADO PRUEBA',
            '2015-06-15',
            $request,
            self::MINIMAL_PNG_DATA_URI
        );

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1, AssemblyAttendance::query()
            ->where('assembly_id', $assembly->id)
            ->where('document_number', '1000918728')
            ->count());

        $result->refresh();
        $this->assertSame('10.0.0.5', $result->ip_address);
        $this->assertStringContainsString('Second-Auth', $result->user_agent ?? '');
        $this->assertTrue($result->authenticated_at->greaterThan($existing->authenticated_at));
    }

    public function test_record_creates_single_row_on_first_authentication(): void
    {
        Storage::fake('prosalud-private');

        Assembly::query()->create([
            'name' => 'Asamblea test',
            'description' => null,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
            'allows_reactivation' => true,
        ]);

        $request = Request::create('/api/activos/search-hospital', 'POST');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        /** @var AssemblyAttendanceService $service */
        $service = app(AssemblyAttendanceService::class);

        $result = $service->record(
            '999888777',
            'NUEVO DELEGADO',
            '2010-01-20',
            $request,
            self::MINIMAL_PNG_DATA_URI
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->signature_path);
        $this->assertSame(1, AssemblyAttendance::query()->where('document_number', '999888777')->count());
    }
}
