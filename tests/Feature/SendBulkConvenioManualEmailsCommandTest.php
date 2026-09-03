<?php

namespace Tests\Feature;

use Tests\TestCase;

class SendBulkConvenioManualEmailsCommandTest extends TestCase
{
    public function test_dry_run_lists_valid_pdfs_without_dispatching(): void
    {
        $conveniosPath = resource_path('convenios');
        if (! is_dir($conveniosPath)) {
            mkdir($conveniosPath, 0755, true);
        }

        $pdfPath = $conveniosPath.'/000 - DRY RUN USER - 1111111111.pdf';
        file_put_contents($pdfPath, '%PDF-1.4 dry run');

        try {
            $this->artisan('convenios:send-manual-emails', [
                '--dry-run' => true,
                '--limit' => 1,
            ])
                ->expectsOutputToContain('DRY RUN MODE')
                ->expectsOutputToContain('Limitando a 1 archivos')
                ->assertSuccessful();
        } finally {
            if (is_file($pdfPath)) {
                @unlink($pdfPath);
            }
        }
    }
}
