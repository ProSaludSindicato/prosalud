<?php

namespace Tests\Unit;

use App\Mail\RequestFormResponse;
use App\Models\RequestForm;
use App\Support\EmailBodyFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailBodyFormatterTest extends TestCase
{
    #[Test]
    public function it_converts_plain_urls_into_clickable_links(): void
    {
        $input = "Diligencie la encuesta:\n\nhttps://forms.gle/i8CBCi4Jrc9sG23h8";
        $output = EmailBodyFormatter::linkifyUrls($input);

        $this->assertStringContainsString(
            '<a href="https://forms.gle/i8CBCi4Jrc9sG23h8" target="_blank" rel="noopener noreferrer"',
            $output
        );
        $this->assertStringContainsString('>https://forms.gle/i8CBCi4Jrc9sG23h8</a>', $output);
    }

    #[Test]
    public function it_converts_urls_inside_html_paragraphs(): void
    {
        $input = '<p>Complete la encuesta en https://forms.gle/i8CBCi4Jrc9sG23h8 por favor.</p>';
        $output = EmailBodyFormatter::linkifyUrls($input);

        $this->assertStringContainsString(
            '<a href="https://forms.gle/i8CBCi4Jrc9sG23h8"',
            $output
        );
        $this->assertSame(1, substr_count($output, '<a href='));
    }

    #[Test]
    public function it_does_not_linkify_urls_that_are_already_inside_anchor_tags(): void
    {
        $input = '<p>Visite <a href="https://forms.gle/i8CBCi4Jrc9sG23h8">este enlace</a>.</p>';
        $output = EmailBodyFormatter::linkifyUrls($input);

        $this->assertSame($input, $output);
    }

    #[Test]
    public function it_leaves_text_without_urls_unchanged(): void
    {
        $input = '<p>Su solicitud ha sido completada exitosamente.</p>';
        $output = EmailBodyFormatter::linkifyUrls($input);

        $this->assertSame($input, $output);
    }

    #[Test]
    public function request_form_response_mailable_renders_urls_as_clickable_links(): void
    {
        $requestForm = new RequestForm([
            'id' => '1234567890',
            'full_name' => 'Usuario Prueba',
            'request_type' => 'retiro-sindical',
            'status' => 'COMPLETED',
        ]);

        $emailBody = '<p>Complete la encuesta: https://forms.gle/i8CBCi4Jrc9sG23h8</p>';

        $mailable = new RequestFormResponse(
            $requestForm,
            'Retiro sindical completado',
            $emailBody,
            'COMPLETED',
        );

        $html = $mailable->render();

        $this->assertStringContainsString(
            '<a href="https://forms.gle/i8CBCi4Jrc9sG23h8"',
            $html
        );
    }
}
