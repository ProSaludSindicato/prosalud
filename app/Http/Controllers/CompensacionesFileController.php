<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadCompensacionesFileRequest;
use App\Services\ExcelReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Auth, Log, Storage};
use PhpOffice\PhpSpreadsheet\{Exception as SpreadsheetException, IOFactory};
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompensacionesFileController extends Controller
{
    private const FILE_NAME = 'COMPENSACIONES_AFILIADOS_ACTIVOS.xlsx';
    private const FILE_PATH = 'data/' . self::FILE_NAME;
    private const BACKUP_DIRECTORY = 'data/backups/compensaciones';
    private const PRIMARY_DISK = 'prosalud-private';
    private const FALLBACK_DISK = 'local';
    private const REQUIRED_SHEET_NAME = 'DINAMICA';

    /**
     * Upload and replace the compensaciones Excel file in the private bucket.
     * Creates a backup of the previous file with timestamp for audit trail.
     */
    public function upload(UploadCompensacionesFileRequest $request, ExcelReaderService $excelReaderService): JsonResponse
    {
        try {
            $file = $request->file('file');

            // Validar archivo antes de guardar (ya validado en Request, pero verificamos estructura nuevamente)
            try {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getSheetByName(self::REQUIRED_SHEET_NAME);
                
                if (!$worksheet) {
                    return response()->json([
                        'success' => false,
                        'message' => "El archivo debe contener una hoja llamada 'DINAMICA'.",
                        'error_code' => 'INVALID_SHEET',
                    ], 422);
                }

                $data = $worksheet->toArray();

                if (count($data) < 2) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo Excel parece estar vacío o no tiene el formato esperado.',
                        'error_code' => 'INVALID_EXCEL_FORMAT',
                    ], 422);
                }
            } catch (SpreadsheetException $e) {
                Log::warning('Archivo de compensaciones inválido', [
                    'error' => $e->getMessage(),
                    'filename' => $file->getClientOriginalName(),
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'El archivo Excel no es válido o está corrupto.',
                    'error_code' => 'INVALID_EXCEL_FILE',
                ], 422);
            }

            $disk = self::PRIMARY_DISK;
            $storedPath = null;
            $backupPath = null;

            try {
                // 1. Crear respaldo del archivo anterior si existe
                $backupPath = $this->createBackup($disk);

                // 2. Subir el nuevo archivo
                $storedPath = Storage::disk($disk)->putFileAs('data', $file, self::FILE_NAME);

                if (false === $storedPath) {
                    throw new \Exception('Error al subir archivo al bucket privado');
                }

                Log::info('Archivo de compensaciones subido a bucket privado exitosamente', [
                    'file_path' => $storedPath,
                    'file_size' => $file->getSize(),
                    'original_filename' => $file->getClientOriginalName(),
                    'disk' => $disk,
                    'uploaded_by' => Auth::check() ? Auth::id() : 'anonymous',
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                    'backup_path' => $backupPath,
                    'rows_count' => count($data),
                ]);
            } catch (\Exception $e) {
                Log::warning('Fallo al subir archivo de compensaciones a bucket privado, intentando fallback', [
                    'error' => $e->getMessage(),
                    'fallback_disk' => self::FALLBACK_DISK,
                    'ip_address' => $request->ip(),
                ]);

                try {
                    $disk = self::FALLBACK_DISK;
                    
                    // 1. Crear respaldo del archivo anterior si existe
                    $backupPath = $this->createBackup($disk);

                    // 2. Subir el nuevo archivo
                    $storedPath = Storage::disk($disk)->putFileAs('data', $file, self::FILE_NAME);

                    if (false === $storedPath) {
                        throw new \Exception('Error al subir archivo al disco de fallback');
                    }

                    Log::info('Archivo de compensaciones guardado en disco local (fallback)', [
                        'file_path' => $storedPath,
                        'file_size' => $file->getSize(),
                        'disk' => $disk,
                        'backup_path' => $backupPath,
                    ]);
                } catch (\Exception $fallbackError) {
                    Log::error('Error al guardar archivo de compensaciones en dispositivos configurados', [
                        'error' => $fallbackError->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Error al guardar el archivo de compensaciones.',
                        'error_code' => 'STORAGE_ERROR',
                    ], 500);
                }
            }

            // Limpiar caché si existe
            // Nota: Si ExcelReaderService usa caché para compensaciones, debería limpiarse aquí

            return response()->json([
                'success' => true,
                'message' => 'Archivo de compensaciones actualizado exitosamente.',
                'file_path' => $storedPath,
                'file_size' => $file->getSize(),
                'disk' => $disk,
                'backup_path' => $backupPath,
                'rows_count' => count($data),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error inesperado al subir archivo de compensaciones', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error inesperado al subir el archivo.',
                'error_code' => 'UNEXPECTED_ERROR',
            ], 500);
        }
    }

    /**
     * Create a backup of the existing compensaciones file with timestamp.
     * 
     * @param string $disk
     * @return string|null Backup path or null if no file existed
     */
    private function createBackup(string $disk): ?string
    {
        try {
            // Verificar si existe el archivo actual
            if (!Storage::disk($disk)->exists(self::FILE_PATH)) {
                Log::info('No existe archivo previo de compensaciones para respaldar', [
                    'disk' => $disk,
                ]);
                return null;
            }

            // Generar nombre de respaldo con timestamp
            $timestamp = now()->format('Y-m-d_His');
            $backupFileName = 'COMPENSACIONES_AFILIADOS_ACTIVOS_' . $timestamp . '.xlsx';
            $backupPath = self::BACKUP_DIRECTORY . '/' . $backupFileName;

            // Leer el archivo actual
            $fileContent = Storage::disk($disk)->get(self::FILE_PATH);

            // Guardar como respaldo
            Storage::disk($disk)->put($backupPath, $fileContent);

            Log::info('Respaldo de archivo de compensaciones creado exitosamente', [
                'original_path' => self::FILE_PATH,
                'backup_path' => $backupPath,
                'disk' => $disk,
                'timestamp' => $timestamp,
            ]);

            return $backupPath;
        } catch (\Exception $e) {
            Log::error('Error al crear respaldo de archivo de compensaciones', [
                'error' => $e->getMessage(),
                'disk' => $disk,
                'trace' => $e->getTraceAsString(),
            ]);

            // No fallar la operación si el respaldo falla, solo registrar el error
            return null;
        }
    }

    /**
     * Download the compensaciones Excel file.
     */
    public function download(): StreamedResponse|JsonResponse
    {
        try {
            $filePath = self::FILE_PATH;
            $disk = self::PRIMARY_DISK;

            // Intentar leer desde bucket privado primero
            if (!Storage::disk($disk)->exists($filePath)) {
                // Si no existe en bucket privado, intentar disco local (desarrollo)
                $disk = self::FALLBACK_DISK;
                if (!Storage::disk($disk)->exists($filePath)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo de compensaciones no existe',
                        'error_code' => 'FILE_NOT_FOUND',
                    ], 404);
                }
            }

            try {
                // Obtener el contenido del archivo
                $fileContent = Storage::disk($disk)->get($filePath);

                Log::info('Archivo de compensaciones descargado', [
                    'file_path' => $filePath,
                    'disk' => $disk,
                    'downloaded_by' => Auth::check() ? Auth::id() : 'anonymous',
                    'timestamp' => now()->toISOString(),
                ]);

                // Descargar el archivo con el nombre original
                return response()->streamDownload(function () use ($fileContent) {
                    echo $fileContent;
                }, self::FILE_NAME, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="' . self::FILE_NAME . '"',
                ]);
            } catch (\Exception $e) {
                Log::error('Error al descargar archivo de compensaciones', [
                    'error' => $e->getMessage(),
                    'file_path' => $filePath,
                    'disk' => $disk,
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al descargar el archivo',
                    'error_code' => 'DOWNLOAD_ERROR',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error inesperado al descargar archivo de compensaciones', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error inesperado al descargar el archivo',
                'error_code' => 'UNEXPECTED_ERROR',
            ], 500);
        }
    }

    /**
     * Get information about the current compensaciones file.
     */
    public function info(): JsonResponse
    {
        try {
            $filePath = self::FILE_PATH;
            $disk = self::PRIMARY_DISK;

            // Intentar leer desde bucket privado primero
            if (!Storage::disk($disk)->exists($filePath)) {
                // Si no existe en bucket privado, intentar disco local (desarrollo)
                $disk = self::FALLBACK_DISK;
                if (!Storage::disk($disk)->exists($filePath)) {
                    return response()->json([
                        'success' => true,
                        'exists' => false,
                        'message' => 'El archivo de compensaciones no existe',
                    ]);
                }
            }

            $fileInfo = [
                'success' => true,
                'exists' => true,
                'file_path' => $filePath,
                'disk' => $disk,
            ];

            // Obtener tamaño y fecha de modificación si está disponible
            try {
                $size = Storage::disk($disk)->size($filePath);
                $lastModified = Storage::disk($disk)->lastModified($filePath);

                $fileInfo['file_size'] = $size;
                $fileInfo['last_modified'] = date('c', $lastModified);
                $fileInfo['readable'] = true;
            } catch (\Exception $e) {
                Log::warning('No se pudo obtener metadata del archivo de compensaciones', [
                    'error' => $e->getMessage(),
                    'disk' => $disk,
                ]);
                $fileInfo['readable'] = false;
            }

            return response()->json($fileInfo);
        } catch (\Exception $e) {
            Log::error('Error al obtener información del archivo de compensaciones', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del archivo',
                'error_code' => 'INFO_ERROR',
            ], 500);
        }
    }
}

