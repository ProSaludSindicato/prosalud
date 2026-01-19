<?php

namespace App\Console\Commands;

// ============================================================================
// CÓDIGO TEMPORAL PARA PRUEBAS - ELIMINAR DESPUÉS DE VERIFICAR FUNCIONAMIENTO
// ============================================================================
// Este comando es solo para probar que las colas y el envío masivo funcionen
// correctamente en producción. Los correos se envían a "juanpapabon@gmail.com"
// ============================================================================

use App\Jobs\TestSendConvenioManualEmailJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestBulkConvenioManualEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:convenios-manual-emails
                            {--limit=5 : Number of test emails to send}
                            {--dry-run : Show what would be sent without actually sending}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'TEMPORAL: Test bulk email sending with queues. Sends to juanpapabon@gmail.com. DELETE AFTER TESTING.';

    /**
     * Rate limit: 6 emails per second = 1 email every 166.67 milliseconds
     */
    private const EMAILS_PER_SECOND = 6;
    private const DELAY_MS = 1000 / self::EMAILS_PER_SECOND; // ~166.67 ms
    private const TEST_EMAIL = 'juanpapabon@gmail.com'; // Hardcoded for testing

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('⚠️  CÓDIGO TEMPORAL PARA PRUEBAS - ELIMINAR DESPUÉS DE VERIFICAR');
        $this->line('');
        $this->info('📧 Test Bulk Convenio Manual Email Sender');
        $this->line('==========================================');
        $this->line('');

        $conveniosPath = resource_path('convenios');

        if (!is_dir($conveniosPath)) {
            $this->error("❌ Directorio de convenios no encontrado: {$conveniosPath}");
            return 1;
        }

        // Get all PDF files
        $pdfFiles = glob($conveniosPath . '/*.pdf');

        if (empty($pdfFiles)) {
            $this->warn('⚠️  No se encontraron archivos PDF en el directorio de convenios.');
            return 0;
        }

        $this->info("📁 Encontrados " . count($pdfFiles) . " archivos PDF");
        $this->line('');

        // Parse files and extract document numbers and convenio names
        $filesToProcess = [];
        foreach ($pdfFiles as $file) {
            $filename = basename($file);
            $filenameWithoutExt = basename($file, '.pdf');
            
            $documento = $this->extractDocumentNumber($filenameWithoutExt);
            $nombreConvenio = $this->extractNombreConvenio($filenameWithoutExt);

            if ($documento && $nombreConvenio) {
                $filesToProcess[] = [
                    'file' => $file,
                    'filename' => $filename,
                    'documento' => $documento,
                    'nombre_convenio' => $nombreConvenio,
                    'ruta_archivo_pdf' => $file,
                ];
            } else {
                $missing = [];
                if (!$documento) $missing[] = 'documento';
                if (!$nombreConvenio) $missing[] = 'nombre_convenio';
                $this->warn("⚠️  No se pudo extraer " . implode(' y ', $missing) . " de: " . $filename);
            }
        }

        if (empty($filesToProcess)) {
            $this->error('❌ No se encontraron archivos con números de documento y nombres de convenio válidos.');
            return 1;
        }

        $this->info("✅ Archivos válidos para procesar: " . count($filesToProcess));
        $this->line('');

        // Apply limit
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $filesToProcess = array_slice($filesToProcess, 0, $limit);
            $this->info("🔢 Limitando a {$limit} archivos para prueba");
            $this->line('');
        }

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No se enviarán correos');
            $this->line('');
            $this->info('Archivos que se procesarían:');
            $this->line('');

            $tableData = [];
            foreach ($filesToProcess as $item) {
                $tableData[] = [
                    'Archivo' => $item['filename'],
                    'Documento' => $item['documento'],
                    'Convenio' => $item['nombre_convenio'],
                    'Email Destino' => self::TEST_EMAIL,
                ];
            }

            $this->table(['Archivo', 'Documento', 'Convenio', 'Email Destino'], $tableData);
            $this->line('');
            $this->info("Total: " . count($filesToProcess) . " correos se enviarían a " . self::TEST_EMAIL);
            $this->info("Tiempo estimado: " . $this->calculateEstimatedTime(count($filesToProcess)));

            return 0;
        }

        // Confirmation
        $this->warn("⚠️  Se enviarán " . count($filesToProcess) . " correos electrónicos de PRUEBA");
        $this->info("📧 Todos los correos se enviarán a: " . self::TEST_EMAIL);
        $this->info("⏱️  Tiempo estimado: " . $this->calculateEstimatedTime(count($filesToProcess)));
        $this->line('');
        $this->warn("📊 Rate limit: " . self::EMAILS_PER_SECOND . " correos por segundo");
        $this->line('');

        if (!$this->option('force')) {
            if (!$this->confirm('¿Deseas continuar con el envío de prueba?')) {
                $this->info('❌ Operación cancelada por el usuario.');
                return 0;
            }
        } else {
            $this->info('✅ Modo --force activado, saltando confirmación...');
            $this->line('');
        }

        // Process files and dispatch jobs with rate limiting
        $this->info('');
        $this->info('📤 Encolando jobs de prueba...');
        $this->line('');

        $progressBar = $this->output->createProgressBar(count($filesToProcess));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
        $progressBar->setMessage('Iniciando...');
        $progressBar->start();

        $enqueued = 0;
        $errors = 0;

        foreach ($filesToProcess as $index => $item) {
            try {
                // Verify file exists
                if (!file_exists($item['ruta_archivo_pdf'])) {
                    $errors++;
                    Log::warning('Archivo PDF no encontrado al encolar job de prueba', [
                        'archivo' => $item['filename'],
                        'ruta' => $item['ruta_archivo_pdf'],
                    ]);
                    $progressBar->setMessage("Error: Archivo no encontrado - {$item['filename']}");
                    $progressBar->advance();
                    continue;
                }

                // Calculate delay: each job should be delayed by (index * delay_ms) milliseconds
                $delaySeconds = ($index * self::DELAY_MS) / 1000;

                // Dispatch test job with delay
                TestSendConvenioManualEmailJob::dispatch(
                    $item['documento'],
                    $item['filename'],
                    $item['ruta_archivo_pdf'],
                    $item['nombre_convenio']
                )->delay(now()->addSeconds($delaySeconds));

                $enqueued++;
                $progressBar->setMessage("Encolado: {$item['filename']}");
            } catch (\Exception $e) {
                $errors++;
                Log::error('Error al encolar job de prueba de correo de convenio manual', [
                    'archivo' => $item['filename'],
                    'documento' => $item['documento'],
                    'error' => $e->getMessage(),
                ]);
                $progressBar->setMessage("Error: {$item['filename']}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');

        // Summary
        $this->info('✅ Procesamiento completado');
        $this->line('');
        $this->info("📊 Resumen:");
        $this->line("  • Jobs encolados: {$enqueued}");
        if ($errors > 0) {
            $this->warn("  • Errores: {$errors}");
        }
        $this->line("  • Email destino: " . self::TEST_EMAIL);
        $this->line("  • Tiempo estimado: " . $this->calculateEstimatedTime($enqueued));
        $this->line('');
        $this->warn('⚠️  Verifica los logs y la cola para confirmar que los jobs se procesaron correctamente.');
        $this->warn('⚠️  RECUERDA: Este código es TEMPORAL y debe ser ELIMINADO después de las pruebas.');

        Log::info('Bulk convenio manual test emails queued', [
            'total_files' => count($filesToProcess),
            'enqueued' => $enqueued,
            'errors' => $errors,
            'test_email' => self::TEST_EMAIL,
            'rate_limit' => self::EMAILS_PER_SECOND . ' emails/second',
        ]);

        return 0;
    }

    /**
     * Extract document number from filename.
     * Format: "NOMBRE CONVENIO - NOMBRE COMPLETO - 1234567890"
     */
    private function extractDocumentNumber(string $filename): ?string
    {
        $parts = explode(' - ', $filename);

        if (count($parts) < 2) {
            return null;
        }

        $documento = end($parts);
        $documento = preg_replace('/[^0-9]/', '', $documento);

        return !empty($documento) ? $documento : null;
    }

    /**
     * Extract convenio name from filename.
     */
    private function extractNombreConvenio(string $filename): ?string
    {
        $parts = explode(' - ', $filename);

        if (count($parts) < 2) {
            return null;
        }

        $nombreConvenio = trim($parts[0]);

        return !empty($nombreConvenio) ? $nombreConvenio : null;
    }

    /**
     * Calculate estimated time for sending emails.
     */
    private function calculateEstimatedTime(int $count): string
    {
        if ($count === 0) {
            return '0 segundos';
        }

        $seconds = $count / self::EMAILS_PER_SECOND;

        if ($seconds < 60) {
            return round($seconds, 1) . ' segundos';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = round($seconds % 60);

        if ($minutes < 60) {
            return "{$minutes} minutos y {$remainingSeconds} segundos";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return "{$hours} horas, {$remainingMinutes} minutos";
    }
}
