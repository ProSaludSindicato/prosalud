<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\SstDeliveryRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SstDeliveryReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        Permission::findOrCreate('dotacion.view');
        $this->user->givePermissionTo('dotacion.view');
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

    private function authenticatedGet(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($this->user))
            ->get($uri, ['Accept' => 'application/json']);
    }

    public function test_filter_options_returns_distinct_hospitals_and_delivered_by(): void
    {
        SstDeliveryRecord::query()->create([
            'id' => (string) Str::uuid(),
            'affiliate_id' => 'CC-123456',
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => '123456',
            'affiliate_hospital' => 'Hospital Central',
            'delivered_by_user_id' => $this->user->id,
            'delivered_by_name' => 'Usuario Prueba',
            'delivered_at' => now(),
            'signed_document_type' => 'CC',
            'signed_document_number' => '123456',
            'delivery_type' => 'first_time',
        ]);

        SstDeliveryRecord::query()->create([
            'id' => (string) Str::uuid(),
            'affiliate_id' => 'CC-654321',
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => '654321',
            'affiliate_hospital' => 'Clínica Norte',
            'delivered_by_user_id' => $this->user->id,
            'delivered_by_name' => 'Usuario Prueba',
            'delivered_at' => now(),
            'signed_document_type' => 'CC',
            'signed_document_number' => '654321',
            'delivery_type' => 'periodic',
        ]);

        $response = $this->authenticatedGet('/api/dotacion-epp/reports/deliveries/filter-options');

        $response->assertOk()
            ->assertJsonFragment(['hospitals' => ['Clínica Norte', 'Hospital Central']])
            ->assertJsonPath('deliveredBy.0.id', (string) $this->user->id)
            ->assertJsonPath('deliveredBy.0.name', 'Usuario Prueba');
    }

    public function test_excel_export_always_returns_accepted_with_job_id(): void
    {
        $response = $this->authenticatedGet('/api/dotacion-epp/reports/deliveries/excel?hospital=Hospital+Central');

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['job_id', 'check_status_url']);

        $jobId = $response->json('job_id');
        $this->assertNotNull(cache()->get("sst_report:{$jobId}"));
    }

    public function test_excel_export_rejects_invalid_date_range(): void
    {
        $response = $this->authenticatedGet(
            '/api/dotacion-epp/reports/deliveries/excel?startDate=2026-06-10&endDate=2026-06-01'
        );

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }
}
