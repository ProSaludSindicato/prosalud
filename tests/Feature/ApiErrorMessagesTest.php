<?php

namespace Tests\Feature;

use App\Mail\ConvenioFirmaNotification;
use Tests\TestCase;

class ApiErrorMessagesTest extends TestCase
{
    public function test_method_not_allowed_message_keeps_its_accents(): void
    {
        $this->postJson('/api/voting/mode')
            ->assertStatus(405)
            ->assertJsonPath('message', 'Método no permitido.');
    }

    public function test_bootstrap_error_messages_have_no_replacement_characters(): void
    {
        $source = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertDoesNotMatchRegularExpression('/[a-záéíóúñ]\?[a-záéíóúñ\s]/iu', preg_replace('/\?\?|\?->|\s\?\s|\?:|\?\)/', '', $source));
    }

    public function test_signing_notification_links_to_the_configured_frontend_not_a_private_ip(): void
    {
        config(['services.frontend.url' => 'https://panel.example.org/']);

        $html = (new ConvenioFirmaNotification('Ana Pérez', 'convenio.pdf'))->render();

        $this->assertStringContainsString('https://panel.example.org/servicios/firma-convenio', $html);
        $this->assertStringNotContainsString('192.168.', $html);
    }
}
