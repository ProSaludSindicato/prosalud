<?php

namespace Tests\Feature;

use App\Constants\RequestStatuses;
use App\Constants\RequestTypes;
use App\Models\ApiToken;
use App\Models\RequestForm;
use App\Models\RequestResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RequestIndexPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        Role::findByName('admin')->givePermissionTo('requests.view');
        $this->admin->assignRole('admin');
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

    private function authenticatedGet(string $uri, User $user): \Illuminate\Testing\TestResponse
    {
        return $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->get($uri, ['Accept' => 'application/json']);
    }

    public function test_index_returns_slim_payload_without_responses_or_file_urls(): void
    {
        $requestForm = RequestForm::factory()->create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'status' => RequestStatuses::PENDING,
            'payload' => [
                'proceso' => 'Proceso visible',
                'dondeRealizaProceso' => 'Hospital Central',
                'campoExtra' => 'No debe aparecer en listado',
            ],
            'files' => [
                'certificado' => [
                    'original_name' => 'certificado.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 1024,
                    'original_key' => 'certificado',
                    'disk' => 'prosalud-private',
                    'path' => 'requests/test/certificado.pdf',
                ],
            ],
        ]);

        RequestResponse::create([
            'request_form_id' => $requestForm->id,
            'responded_by' => $this->admin->id,
            'status' => RequestStatuses::COMPLETED,
            'email_subject' => 'Respuesta',
            'email_body' => 'Cuerpo largo de respuesta que no debe ir en el listado',
            'created_at' => now(),
        ]);

        $response = $this->authenticatedGet('/api/requests', $this->admin);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $item = collect($response->json('data'))->firstWhere('id', $requestForm->id);

        $this->assertNotNull($item);
        $this->assertSame(1, $item['responses_count']);
        $this->assertSame(1, $item['files_count']);
        $this->assertArrayNotHasKey('responses', $item);
        $this->assertArrayNotHasKey('files', $item);
        $this->assertSame('Proceso visible', $item['payload']['proceso'] ?? null);
        $this->assertSame('Hospital Central', $item['payload']['dondeRealizaProceso'] ?? null);
        $this->assertArrayNotHasKey('campoExtra', $item['payload'] ?? []);
    }

    public function test_show_still_returns_full_request_payload_and_responses(): void
    {
        $requestForm = RequestForm::factory()->create([
            'payload' => [
                'proceso' => 'Proceso completo',
                'campoExtra' => 'Detalle completo',
            ],
        ]);

        RequestResponse::create([
            'request_form_id' => $requestForm->id,
            'responded_by' => $this->admin->id,
            'status' => RequestStatuses::COMPLETED,
            'email_subject' => 'Respuesta completa',
            'email_body' => 'Cuerpo completo',
            'created_at' => now(),
        ]);

        $response = $this->authenticatedGet("/api/requests/{$requestForm->id}", $this->admin);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $payload = json_decode($response->json('data.payload'), true);
        $this->assertSame('Detalle completo', $payload['campoExtra'] ?? null);
        $this->assertCount(1, $response->json('data.responses'));
        $this->assertSame('Cuerpo completo', $response->json('data.responses.0.email_body'));
    }
}
