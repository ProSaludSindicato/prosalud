<?php

namespace Tests\Feature;

use App\Constants\RequestStatuses;
use App\Constants\RequestTypes;
use App\Models\RequestForm;
use App\Models\RequestStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_validation_changes_status_to_in_review_automatically()
    {
        // Create a request in PENDING status
        $request = RequestForm::factory()->create([
            'status' => RequestStatuses::PENDING,
            'validated_at' => null,
            'validated_by' => null,
        ]);

        $this->assertEquals(RequestStatuses::PENDING, $request->status);
        $this->assertNull($request->validated_at);
        $this->assertNull($request->validated_by);

        // Call the validate endpoint
        $response = $this->postJson("/api/requests/{$request->id}/validate");

        // Assert successful response
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Solicitud validada exitosamente y cambiada a estado "En revisión"',
            ]);

        // Assert request was updated
        $request->refresh();
        $this->assertEquals(RequestStatuses::IN_REVIEW, $request->status);
        $this->assertNotNull($request->validated_at);
        $this->assertEquals($this->user->id, $request->validated_by);

        // Assert status log was created
        $statusLog = RequestStatusLog::where('request_form_id', $request->id)->first();
        $this->assertNotNull($statusLog);
        $this->assertEquals(RequestStatuses::PENDING, $statusLog->old_status);
        $this->assertEquals(RequestStatuses::IN_REVIEW, $statusLog->new_status);
        $this->assertEquals($this->user->id, $statusLog->changed_by);
        $this->assertEquals('Solicitud validada', $statusLog->reason);
    }

    public function test_validation_does_not_change_status_if_already_in_review()
    {
        // Create a request already in IN_REVIEW status
        $request = RequestForm::factory()->create([
            'status' => RequestStatuses::IN_REVIEW,
            'validated_at' => null,
            'validated_by' => null,
        ]);

        // Call the validate endpoint
        $response = $this->postJson("/api/requests/{$request->id}/validate");

        // Assert successful response
        $response->assertStatus(200);

        // Assert request was updated with validation info but status remained IN_REVIEW
        $request->refresh();
        $this->assertEquals(RequestStatuses::IN_REVIEW, $request->status);
        $this->assertNotNull($request->validated_at);
        $this->assertEquals($this->user->id, $request->validated_by);

        // Assert no status log was created (since status didn't change)
        $statusLog = RequestStatusLog::where('request_form_id', $request->id)
            ->where('old_status', RequestStatuses::IN_REVIEW)
            ->where('new_status', RequestStatuses::IN_REVIEW)
            ->first();
        $this->assertNull($statusLog);
    }

    public function test_cannot_validate_already_validated_request()
    {
        // Create an already validated request
        $request = RequestForm::factory()->create([
            'validated_at' => now(),
            'validated_by' => $this->user->id,
        ]);

        // Call the validate endpoint
        $response = $this->postJson("/api/requests/{$request->id}/validate");

        // Assert error response
        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'La solicitud ya ha sido validada',
            ]);
    }

    /**
     * Test auto-validation for compensacion-descanso requests
     */
    public function test_compensacion_descanso_auto_validated_on_status_change()
    {
        $requestTypes = [
            RequestTypes::COMPENSACION_DESCANSO,
            RequestTypes::COMPENSACION_ANUAL,
            RequestTypes::VERIFICACION_PAGOS,
        ];

        $statuses = [
            RequestStatuses::IN_REVIEW,
            RequestStatuses::COMPLETED,
            RequestStatuses::REJECTED,
        ];

        foreach ($requestTypes as $requestType) {
            foreach ($statuses as $status) {
                $this->autoValidationTest($requestType, $status);
            }
        }
    }

    private function autoValidationTest(string $requestType, string $status)
    {
        // Create a request of specific type in PENDING status, not validated
        $request = RequestForm::factory()->create([
            'request_type' => $requestType,
            'status' => RequestStatuses::PENDING,
            'validated_at' => null,
            'validated_by' => null,
        ]);

        // Change status
        $response = $this->patchJson("/api/requests/{$request->id}/status", [
            'status' => $status,
            'status_reason' => 'Test status change',
        ]);

        // Assert successful response with auto-validation message
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'auto_validated' => true,
            ]);

        // Assert request was validated
        $request->refresh();
        $this->assertEquals($status, $request->status);
        $this->assertNotNull($request->validated_at);
        $this->assertEquals($this->user->id, $request->validated_by);
    }

    public function test_other_request_types_not_auto_validated()
    {
        $otherRequestTypes = [
            RequestTypes::CERTIFICADO_CONVENIO,
            RequestTypes::ACTUALIZAR_DATOS_PERSONALES,
            RequestTypes::INCAPACIDADES_LICENCIAS,
        ];

        foreach ($otherRequestTypes as $requestType) {
            $request = RequestForm::factory()->create([
                'request_type' => $requestType,
                'status' => RequestStatuses::PENDING,
                'validated_at' => null,
                'validated_by' => null,
            ]);

            // Change status to IN_REVIEW
            $response = $this->patchJson("/api/requests/{$request->id}/status", [
                'status' => RequestStatuses::IN_REVIEW,
                'status_reason' => 'Test status change',
            ]);

            // Assert successful response but NOT auto-validated
            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'auto_validated' => false,
                ]);

            // Assert request was NOT validated
            $request->refresh();
            $this->assertEquals(RequestStatuses::IN_REVIEW, $request->status);
            $this->assertNull($request->validated_at);
            $this->assertNull($request->validated_by);
        }
    }

    public function test_already_validated_requests_not_auto_validated_again()
    {
        $request = RequestForm::factory()->create([
            'request_type' => RequestTypes::COMPENSACION_DESCANSO,
            'status' => RequestStatuses::PENDING,
            'validated_at' => now()->subDay(),
            'validated_by' => $this->user->id,
        ]);

        // Change status
        $response = $this->patchJson("/api/requests/{$request->id}/status", [
            'status' => RequestStatuses::COMPLETED,
            'status_reason' => 'Test status change',
        ]);

        // Assert successful response but NOT auto-validated (already was)
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'auto_validated' => false,
            ]);

        // Assert validation info unchanged
        $request->refresh();
        $this->assertEquals(RequestStatuses::COMPLETED, $request->status);
        $this->assertNotNull($request->validated_at);
        $this->assertEquals($this->user->id, $request->validated_by);
    }
}
