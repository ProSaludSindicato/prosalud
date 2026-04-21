<?php

namespace App\Console\Commands;

use App\Jobs\SendConvenioManualEmailJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBulkConvenioManualEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'convenios:send-manual-emails
                            {--dry-run : Show what would be sent without actually sending}
                            {--limit= : Limit the number of emails to send}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send bulk emails to affiliates with attached PDF convenios. Rate limited to 6 emails per second.';

    /**
     * Rate limit: 6 emails per second = 1 email every 166.67 milliseconds
     */
    private const EMAILS_PER_SECOND = 6;

    private const DELAY_MS = 1000 / self::EMAILS_PER_SECOND; // ~166.67 ms

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('📧 Bulk Convenio Manual Email Sender');
        $this->line('=====================================');
        $this->line('');

        $conveniosPath = resource_path('convenios');

        if (! is_dir($conveniosPath)) {
            $this->error("❌ Directorio de convenios no encontrado: {$conveniosPath}");

            return 1;
        }

        // Get all PDF files
        $pdfFiles = glob($conveniosPath.'/*.pdf');

        if (empty($pdfFiles)) {
            $this->warn('⚠️  No se encontraron archivos PDF en el directorio de convenios.');

            return 0;
        }

        $this->info('📁 Encontrados '.count($pdfFiles).' archivos PDF');
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
                if (! $documento) {
                    $missing[] = 'documento';
                }
                if (! $nombreConvenio) {
                    $missing[] = 'nombre_convenio';
                }
                $this->warn('⚠️  No se pudo extraer '.implode(' y ', $missing).' de: '.$filename);
            }
        }

        if (empty($filesToProcess)) {
            $this->error('❌ No se encontraron archivos con números de documento y nombres de convenio válidos.');

            return 1;
        }

        $this->info('✅ Archivos válidos para procesar: '.count($filesToProcess));
        $this->line('');

        // Apply limit if specified
        $limit = $this->option('limit');
        if ($limit && is_numeric($limit)) {
            $limit = (int) $limit;
            $filesToProcess = array_slice($filesToProcess, 0, $limit);
            $this->info("🔢 Limitando a {$limit} archivos");
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
                ];
            }

            $this->table(['Archivo', 'Documento', 'Convenio'], $tableData);
            $this->line('');
            $this->info('Total: '.count($filesToProcess).' correos se enviarían');
            $this->info('Tiempo estimado: '.$this->calculateEstimatedTime(count($filesToProcess)));

            return 0;
        }

        // Confirmation
        $this->warn('⚠️  Se enviarán '.count($filesToProcess).' correos electrónicos con PDFs adjuntos');
        $this->info('⏱️  Tiempo estimado: '.$this->calculateEstimatedTime(count($filesToProcess)));
        $this->line('');
        $this->warn('📊 Rate limit: '.self::EMAILS_PER_SECOND.' correos por segundo');
        $this->line('');

        if (! $this->option('force')) {
            if (! $this->confirm('¿Deseas continuar con el envío masivo?')) {
                $this->info('❌ Operación cancelada por el usuario.');

                return 0;
            }
        } else {
            $this->info('✅ Modo --force activado, saltando confirmación...');
            $this->line('');
        }

        // Process files and dispatch jobs with rate limiting
        $this->info('');
        $this->info('📤 Encolando jobs...');
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
                if (! file_exists($item['ruta_archivo_pdf'])) {
                    $errors++;
                    Log::warning('Archivo PDF no encontrado al encolar job', [
                        'archivo' => $item['filename'],
                        'ruta' => $item['ruta_archivo_pdf'],
                    ]);
                    $progressBar->setMessage("Error: Archivo no encontrado - {$item['filename']}");
                    $progressBar->advance();

                    continue;
                }

                // Calculate delay: each job should be delayed by (index * delay_ms) milliseconds
                // Convert to seconds for Laravel's delay() method
                $delaySeconds = ($index * self::DELAY_MS) / 1000;

                // Dispatch job with delay
                SendConvenioManualEmailJob::dispatch(
                    $item['documento'],
                    $item['filename'],
                    $item['ruta_archivo_pdf'],
                    $item['nombre_convenio'],
                    null,
                    null,
                    null,
                )->delay(now()->addSeconds($delaySeconds));

                $enqueued++;
                $progressBar->setMessage("Encolado: {$item['filename']}");
            } catch (\Exception $e) {
                $errors++;
                Log::error('Error al encolar job de correo de convenio manual', [
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
        $this->info('📊 Resumen:');
        $this->line("  • Jobs encolados: {$enqueued}");
        if ($errors > 0) {
            $this->warn("  • Errores: {$errors}");
        }
        $this->line('  • Tiempo estimado: '.$this->calculateEstimatedTime($enqueued));
        $this->line('');

        Log::info('Bulk convenio manual emails queued', [
            'total_files' => count($filesToProcess),
            'enqueued' => $enqueued,
            'errors' => $errors,
            'rate_limit' => self::EMAILS_PER_SECOND.' emails/second',
        ]);

        return 0;
    }

    /**
     * Extract document number from filename.
     * Format: "NOMBRE CONVENIO - NOMBRE COMPLETO - 1234567890"
     * Returns the last part after " - " as the document number.
     */
    private function extractDocumentNumber(string $filename): ?string
    {
        // Split by " - " to get parts
        $parts = explode(' - ', $filename);

        if (count($parts) < 2) {
            return null;
        }

        // Get the last part (should be the document number)
        $documento = end($parts);

        // Normalize: remove any non-numeric characters and return
        $documento = preg_replace('/[^0-9]/', '', $documento);

        return ! empty($documento) ? $documento : null;
    }

    /**
     * Extract convenio name from filename.
     * Format: "NOMBRE CONVENIO - NOMBRE COMPLETO - 1234567890"
     * Returns the first part before the first " - " as the convenio name.
     */
    private function extractNombreConvenio(string $filename): ?string
    {
        // Split by " - " to get parts
        $parts = explode(' - ', $filename);

        if (count($parts) < 2) {
            return null;
        }

        // Get the first part (should be the convenio name)
        $nombreConvenio = trim($parts[0]);

        return ! empty($nombreConvenio) ? $nombreConvenio : null;
    }

    /**
     * Calculate estimated time for sending emails.
     */
    private function calculateEstimatedTime(int $count): string
    {
        if ($count === 0) {
            return '0 segundos';
        }

        // Time = (count / emails_per_second) seconds
        $seconds = $count / self::EMAILS_PER_SECOND;

        if ($seconds < 60) {
            return round($seconds, 1).' segundos';
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
