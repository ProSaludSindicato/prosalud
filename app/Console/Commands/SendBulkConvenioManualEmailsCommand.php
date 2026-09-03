<?php

namespace App\Console\Commands;

use App\Services\ConvenioPreGeneratedPdfDispatchService;
use App\Support\ConvenioPreGeneratedPdfFilename;
use App\Support\ConvenioRateLimiter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBulkConvenioManualEmailsCommand extends Command
{
    protected $signature = 'convenios:send-manual-emails
                            {--dry-run : Show what would be sent without actually sending}
                            {--limit= : Limit the number of emails to send}
                            {--force : Skip confirmation prompt}
                            {--no-email : Store PDFs in S3 without sending emails}';

    protected $description = 'Send bulk emails to affiliates with pre-generated PDF convenios from resources/convenios/.';

    public function __construct(
        private readonly ConvenioPreGeneratedPdfDispatchService $dispatchService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Bulk Convenio Manual Email Sender');
        $this->line('=====================================');
        $this->line('');

        $conveniosPath = resource_path('convenios');

        if (! is_dir($conveniosPath)) {
            $this->error("Directorio de convenios no encontrado: {$conveniosPath}");

            return 1;
        }

        $pdfFiles = glob($conveniosPath.'/*.pdf');

        if ($pdfFiles === false || $pdfFiles === []) {
            $this->warn('No se encontraron archivos PDF en el directorio de convenios.');

            return 0;
        }

        $this->info('Encontrados '.count($pdfFiles).' archivos PDF');
        $this->line('');

        $filesToProcess = [];
        foreach ($pdfFiles as $file) {
            $filename = basename($file);
            $parsed = ConvenioPreGeneratedPdfFilename::parse($filename);

            if ($parsed !== null) {
                $filesToProcess[] = [
                    'file' => $file,
                    'filename' => $parsed['filename'],
                    'documento' => $parsed['documento'],
                    'nombre_convenio' => $parsed['nombre_convenio'],
                    'ruta_archivo_pdf' => $file,
                ];
            } else {
                $this->warn('No se pudo extraer datos válidos de: '.$filename);
            }
        }

        if ($filesToProcess === []) {
            $this->error('No se encontraron archivos con números de documento y nombres de convenio válidos.');

            return 1;
        }

        $this->info('Archivos válidos para procesar: '.count($filesToProcess));
        $this->line('');

        $limit = $this->option('limit');
        if ($limit && is_numeric($limit)) {
            $limit = (int) $limit;
            $filesToProcess = array_slice($filesToProcess, 0, $limit);
            $this->info("Limitando a {$limit} archivos");
            $this->line('');
        }

        $sendEmail = ! $this->option('no-email');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No se procesarán archivos');
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
            $this->info('Total: '.count($filesToProcess).' archivos');
            if ($sendEmail) {
                $this->info('Tiempo estimado: '.$this->calculateEstimatedTime(count($filesToProcess)));
            }

            return 0;
        }

        if ($sendEmail) {
            $this->warn('Se procesarán '.count($filesToProcess).' PDFs y se encolarán envíos de correo');
            $this->info('Tiempo estimado: '.$this->calculateEstimatedTime(count($filesToProcess)));
            $this->line('');
            $this->warn('Rate limit: '.ConvenioRateLimiter::emailsPerMinute().' correos por minuto');
        } else {
            $this->warn('Se almacenarán '.count($filesToProcess).' PDFs sin enviar correos');
        }
        $this->line('');

        if (! $this->option('force')) {
            if (! $this->confirm('¿Deseas continuar?')) {
                $this->info('Operación cancelada por el usuario.');

                return 0;
            }
        } else {
            $this->info('Modo --force activado, saltando confirmación...');
            $this->line('');
        }

        $this->info('');
        $this->info('Procesando archivos...');
        $this->line('');

        $progressBar = $this->output->createProgressBar(count($filesToProcess));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
        $progressBar->setMessage('Iniciando...');
        $progressBar->start();

        $processed = 0;
        $errors = 0;

        foreach ($filesToProcess as $item) {
            try {
                if (! file_exists($item['ruta_archivo_pdf'])) {
                    $errors++;
                    Log::warning('Archivo PDF no encontrado al procesar', [
                        'archivo' => $item['filename'],
                        'ruta' => $item['ruta_archivo_pdf'],
                    ]);
                    $progressBar->setMessage("Error: Archivo no encontrado - {$item['filename']}");
                    $progressBar->advance();

                    continue;
                }

                $result = $this->dispatchService->persistAndOptionallySend(
                    absolutePdfPath: $item['ruta_archivo_pdf'],
                    filename: $item['filename'],
                    source: 'cli',
                    batchId: null,
                    generatedByUserId: null,
                    sendEmail: $sendEmail,
                );

                if ($result['success']) {
                    $processed++;
                    $progressBar->setMessage("Procesado: {$item['filename']}");
                } else {
                    $errors++;
                    $progressBar->setMessage("Error: {$item['filename']}");
                }
            } catch (\Exception $e) {
                $errors++;
                Log::error('Error al procesar PDF de convenio manual', [
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

        $this->info('Procesamiento completado');
        $this->line('');
        $this->info('Resumen:');
        $this->line("  • Procesados: {$processed}");
        if ($errors > 0) {
            $this->warn("  • Errores: {$errors}");
        }
        if ($sendEmail) {
            $this->line('  • Tiempo estimado de envío: '.$this->calculateEstimatedTime($processed));
        }
        $this->line('');

        Log::info('Bulk convenio manual PDFs processed', [
            'total_files' => count($filesToProcess),
            'processed' => $processed,
            'errors' => $errors,
            'send_email' => $sendEmail,
            'rate_limit' => ConvenioRateLimiter::emailsPerMinute().' emails/minute',
        ]);

        return 0;
    }

    private function calculateEstimatedTime(int $count): string
    {
        if ($count === 0) {
            return '0 segundos';
        }

        $emailsPerMinute = ConvenioRateLimiter::emailsPerMinute();
        $seconds = ($count / $emailsPerMinute) * 60;

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
