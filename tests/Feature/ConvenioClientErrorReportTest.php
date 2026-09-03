<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ConvenioClientErrorReportTest extends TestCase
{
    public function test_valid_payload_is_accepted_and_logged_as_error(): void
    {
        $payload = [
            'message' => 'Cannot read properties of null (reading \'getPage\')',
            'stack' => 'TypeError: Cannot read properties of null at DocumentEditorViewer',
            'component_stack' => 'at DocumentEditorViewer at SignConvenioByToken',
            'url' => 'https://firma.prosalud.org.co/sign/abc123token',
            'token' => str_repeat('a', 64),
            'context' => [
                'user_agent' => 'Mozilla/5.0 (Linux; Android 14)',
                'device_memory' => 2,
                'viewport' => '360x780',
                'phase' => 'submit',
            ],
        ];

        $loggedFrontendError = false;

        Log::shouldReceive('error')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $message, array $context = []) use (&$loggedFrontendError, $payload): void {
                if ($message !== '[CONVENIO DIGITAL][FRONTEND] Error no controlado en visor de firma') {
                    return;
                }

                $loggedFrontendError = $context['message'] === $payload['message']
                    && $context['token'] === $payload['token']
                    && $context['url'] === $payload['url']
                    && $context['stack'] === $payload['stack']
                    && $context['component_stack'] === $payload['component_stack']
                    && ($context['context']['phase'] ?? null) === 'submit';
            });

        $this->postJson('/api/public/convenio-firma/report-error', $payload)
            ->assertAccepted()
            ->assertJsonPath('success', true);

        $this->assertTrue($loggedFrontendError);
    }

    public function test_missing_message_returns_validation_error(): void
    {
        $this->postJson('/api/public/convenio-firma/report-error', [
            'url' => 'https://firma.prosalud.org.co/sign/abc',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed');
    }

    public function test_message_exceeding_max_length_is_rejected(): void
    {
        $this->postJson('/api/public/convenio-firma/report-error', [
            'message' => str_repeat('x', 2001),
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
