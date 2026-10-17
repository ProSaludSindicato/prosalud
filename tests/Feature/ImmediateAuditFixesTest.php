<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\RequestForm;
use App\Models\RequestResponse;
use App\Models\RequestResponseAttachment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImmediateAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['services.recaptcha.enabled' => false]);
    }

    private function actingWithToken(User $user): static
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $plainToken);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_activity_realized_show_requires_authentication(): void
    {
        $this->getJson('/api/wellness-requests/1/activity-realized')->assertStatus(401);
    }

    public function test_activity_realized_show_requires_wellness_requests_view_permission(): void
    {
        $user = $this->userWithPermissions('requests.view');

        $this->actingWithToken($user)
            ->getJson('/api/wellness-requests/1/activity-realized')
            ->assertForbidden();
    }

    public function test_activity_realized_show_does_not_reveal_unknown_requests(): void
    {
        $user = $this->userWithPermissions('wellness_requests.view');

        $this->actingWithToken($user)
            ->getJson('/api/wellness-requests/999999/activity-realized')
            ->assertForbidden();
    }

    public function test_retry_certificate_generation_requires_requests_respond_permission(): void
    {
        $requestForm = RequestForm::factory()->create();
        $user = $this->userWithPermissions('requests.view');

        $this->actingWithToken($user)
            ->postJson("/api/requests/{$requestForm->id}/retry-certificate-generation")
            ->assertForbidden();
    }

    public function test_retry_certificate_generation_is_denied_for_unassigned_user(): void
    {
        $requestForm = RequestForm::factory()->create();
        $user = $this->userWithPermissions('requests.respond');

        $this->actingWithToken($user)
            ->postJson("/api/requests/{$requestForm->id}/retry-certificate-generation")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_failed_user_validation_does_not_log_passwords(): void
    {
        $admin = $this->userWithPermissions('users.create');
        Log::spy();

        $this->actingWithToken($admin)->postJson('/api/users', [
            'name' => 'Sin Correo',
            'password' => 'Sup3r-Secret-Pass!',
            'password_confirmation' => 'Sup3r-Secret-Pass!',
        ])->assertStatus(422);

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            return str_contains((string) $message, 'StoreUserRequest')
                && ! str_contains(json_encode($context), 'Sup3r-Secret-Pass!');
        })->once();
    }

    public function test_failed_user_update_validation_does_not_log_passwords(): void
    {
        $admin = $this->userWithPermissions('users.edit');
        $target = User::factory()->create();
        Log::spy();

        $this->actingWithToken($admin)->putJson("/api/users/{$target->id}", [
            'email' => 'not-an-email',
            'password' => 'Sup3r-Secret-Pass!',
            'password_confirmation' => 'Sup3r-Secret-Pass!',
        ])->assertStatus(422);

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            return str_contains((string) $message, 'UpdateUserRequest')
                && ! str_contains(json_encode($context), 'Sup3r-Secret-Pass!');
        })->once();
    }

    public function test_signing_client_error_report_does_not_log_the_plain_token(): void
    {
        config(['convenio_signing.enabled' => true]);
        $token = Str::random(64);
        Log::spy();

        $this->postJson('/api/public/convenio-firma/report-error', [
            'message' => 'boom',
            'url' => "https://example.test/firma/{$token}",
            'token' => $token,
        ])->assertStatus(202);

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use ($token) {
            return str_contains((string) $message, 'FRONTEND')
                && ! str_contains(json_encode($context), $token);
        })->once();
    }

    public function test_change_candidate_requires_votes_manage_not_audit_view(): void
    {
        $user = $this->userWithPermissions('votes.audit.view');

        $this->actingWithToken($user)->putJson('/api/votes/change-candidate', [
            'voter_document_type' => 'CC',
            'voter_document_number' => '123',
            'new_candidate_id' => '999',
        ])->assertForbidden();
    }

    public function test_response_attachment_download_is_denied_for_unassigned_user(): void
    {
        $requestForm = RequestForm::factory()->create();
        $response = RequestResponse::query()->create([
            'request_form_id' => $requestForm->id,
            'status' => 'completed',
            'email_subject' => 'Asunto',
            'email_body' => 'Cuerpo',
            'created_at' => now(),
        ]);
        $attachment = RequestResponseAttachment::query()->create([
            'request_response_id' => $response->id,
            'path' => 'responses/secret.pdf',
            'original_name' => 'secret.pdf',
            'created_at' => now(),
        ]);
        $user = $this->userWithPermissions('requests.view');

        $this->actingWithToken($user)
            ->getJson("/api/requests/responses/{$response->id}/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    public function test_pending_personal_data_updates_only_lists_assigned_requests(): void
    {
        RequestForm::factory()->create([
            'request_type' => 'actualizar-datos-personales',
            'status' => 'pending',
        ]);
        $user = $this->userWithPermissions('requests.view');

        $this->actingWithToken($user)
            ->getJson('/api/requests/pending-personal-data-updates')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_voting_routes_are_rate_limited(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([['POST', '/api/votes'], ['GET', '/api/delegados']] as [$method, $uri]) {
            $route = $routes->match(Request::create($uri, $method));
            $this->assertContains('throttle:voting', $route->gatherMiddleware(), "{$method} {$uri}");
        }

        $assemblyVote = $routes->match(Request::create('/api/assembly/questions/1/votes', 'POST'));
        $this->assertContains('throttle:voting', $assemblyVote->gatherMiddleware());
    }

    public function test_delegates_excel_is_no_longer_in_the_public_directory(): void
    {
        $this->assertFileDoesNotExist(public_path('data/ASAMBLEA_DELEGADOS_PROSALUD.xlsx'));
    }
}
