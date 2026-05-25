<?php

namespace Tests\Feature;

use App\Constants\RequestStatuses;
use App\Constants\RequestTypes;
use App\Models\ApiToken;
use App\Models\RequestForm;
use App\Models\RequestTypeAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RequestIndexPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $assignedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        Role::findByName('admin')->givePermissionTo('requests.view');
        $this->admin->assignRole('admin');

        $this->assignedUser = User::factory()->create();
        $this->assignedUser->givePermissionTo('requests.view');
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

    public function test_index_supports_pagination_and_returns_meta(): void
    {
        RequestForm::factory()->count(12)->create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'status' => RequestStatuses::PENDING,
        ]);

        $pageOne = $this->authenticatedGet('/api/requests?page=1&per_page=5', $this->admin);
        $pageTwo = $this->authenticatedGet('/api/requests?page=2&per_page=5', $this->admin);

        $pageOne->assertOk()
            ->assertJsonPath('pagination.total', 12)
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonCount(5, 'data');

        $pageTwo->assertOk()
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonCount(5, 'data');

        $pageOneIds = collect($pageOne->json('data'))->pluck('id')->all();
        $pageTwoIds = collect($pageTwo->json('data'))->pluck('id')->all();

        $this->assertEmpty(array_intersect($pageOneIds, $pageTwoIds));
    }

    public function test_index_filters_by_status_and_search(): void
    {
        RequestForm::factory()->create([
            'id' => '0000000001',
            'name' => 'Ana',
            'last_name' => 'Perez',
            'status' => RequestStatuses::PENDING,
        ]);

        RequestForm::factory()->create([
            'id' => '0000000002',
            'name' => 'Carlos',
            'last_name' => 'Gomez',
            'status' => RequestStatuses::COMPLETED,
        ]);

        $statusResponse = $this->authenticatedGet('/api/requests?page=1&per_page=10&status=pending', $this->admin);
        $statusResponse->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', '0000000001');

        $searchResponse = $this->authenticatedGet('/api/requests?page=1&per_page=10&search=Carlos', $this->admin);
        $searchResponse->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', '0000000002');
    }

    public function test_stats_endpoint_returns_aggregated_counts(): void
    {
        RequestForm::factory()->create([
            'status' => RequestStatuses::PENDING,
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'validated_at' => null,
        ]);

        RequestForm::factory()->create([
            'status' => RequestStatuses::COMPLETED,
            'processed_at' => now(),
        ]);

        RequestForm::factory()->create([
            'status' => RequestStatuses::REJECTED,
        ]);

        $response = $this->authenticatedGet('/api/requests/stats', $this->admin);

        $response->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.resolved', 1)
            ->assertJsonPath('data.rejected', 1)
            ->assertJsonPath('data.unvalidated', 1);

        $this->assertCount(6, $response->json('data.monthly_counts'));
    }

    public function test_non_admin_user_only_sees_assigned_request_types_in_stats(): void
    {
        RequestForm::factory()->create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'status' => RequestStatuses::PENDING,
        ]);

        RequestForm::factory()->create([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'status' => RequestStatuses::PENDING,
        ]);

        RequestTypeAssignment::create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'user_id' => $this->assignedUser->id,
        ]);

        $response = $this->authenticatedGet('/api/requests/stats', $this->assignedUser);

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.pending', 1);
    }

    public function test_filter_options_respects_user_assignments(): void
    {
        RequestForm::factory()->create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
        ]);

        RequestForm::factory()->create([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'payload' => ['solicitudRelacionadaCon' => 'COMPENSACIÓN ANUAL DIFERIDA'],
        ]);

        RequestTypeAssignment::create([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'user_id' => $this->assignedUser->id,
        ]);

        $response = $this->authenticatedGet('/api/requests/filter-options', $this->assignedUser);

        $response->assertOk()
            ->assertJsonPath('data.request_types.0', RequestTypes::CERTIFICADO_CONVENIO)
            ->assertJsonCount(1, 'data.request_types');
    }

    public function test_verificacion_pagos_subtype_filter_limits_results(): void
    {
        RequestForm::factory()->create([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'payload' => ['solicitudRelacionadaCon' => 'COMPENSACIÓN ANUAL DIFERIDA'],
        ]);

        RequestForm::factory()->create([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'payload' => ['solicitudRelacionadaCon' => 'AUXILIO DE RODAMIENTO'],
        ]);

        $response = $this->authenticatedGet('/api/requests?page=1&per_page=10&request_type=verificacion-pagos&request_subtype='.urlencode('AUXILIO DE RODAMIENTO'), $this->admin);

        $response->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $payload = $response->json('data.0.payload');
        $this->assertStringContainsString('RODAMIENTO', strtoupper($payload['solicitudRelacionadaCon'] ?? ''));
    }
}
