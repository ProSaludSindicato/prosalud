<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadActivosFileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Auth, Log, Storage};
use PhpOffice\PhpSpreadsheet\{Exception as SpreadsheetException, IOFactory};

class ActivosFileController extends Controller
{
    private const ACTIVOS_FILE_NAME = 'ACTIVOS.xlsx';
    private const ACTIVOS_FILE_PATH = 'data/' . self::ACTIVOS_FILE_NAME;
    private const S3_DISK = 'prosalud-private';
    private const FALLBACK_DISK = 'local';

    /**
     * Upload and replace the ACTIVOS.xlsx file to private bucket.
     */
    public function upload(UploadActivosFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');

            // Validar que el archivo es un Excel válido antes de guardarlo
            try {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getActiveSheet();
                $data = $worksheet->toArray();

                // Validación básica: verificar que tiene al menos una fila de datos
                if (count($data) < 2) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo Excel parece estar vacío o no tiene el formato correcto',
                        'error_code' => 'INVALID_EXCEL_FORMAT',
                    ], 422);
                }
            } catch (SpreadsheetException $e) {
                Log::error('Archivo Excel inválido intentado subir', [
                    'error' => $e->getMessage(),
                    'filename' => $file->getClientOriginalName(),
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'El archivo Excel no es válido o está corrupto',
                    'error_code' => 'INVALID_EXCEL_FILE',
                ], 422);
            }

            $disk = self::S3_DISK;
            $storedPath = null;

            try {
                // Intentar subir a bucket privado
                $storedPath = Storage::disk($disk)->putFileAs('data', $file, self::ACTIVOS_FILE_NAME);

                if (false === $storedPath) {
                    throw new \Exception('Failed to upload to private bucket');
                }

                Log::info('Archivo ACTIVOS.xlsx subido a bucket privado exitosamente', [
                    'file_path' => $storedPath,
                    'file_size' => $file->getSize(),
                    'original_filename' => $file->getClientOriginalName(),
                    'disk' => $disk,
                    'uploaded_by' => Auth::check() ? Auth::id() : 'anonymous',
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Exception $e) {
                Log::warning('Error al subir a bucket privado, intentando disco local como fallback', [
                    'bucket_error' => $e->getMessage(),
                    'fallback_disk' => self::FALLBACK_DISK,
                    'ip_address' => $request->ip(),
                ]);

                // Fallback a disco local si bucket privado falla (útil para desarrollo)
                try {
                    $disk = self::FALLBACK_DISK;
                    $storedPath = Storage::disk($disk)->putFileAs('data', $file, self::ACTIVOS_FILE_NAME);

                    Log::info('Archivo ACTIVOS.xlsx guardado en disco local (fallback)', [
                        'file_path' => $storedPath,
                        'disk' => $disk,
                    ]);
                } catch (\Exception $fallbackError) {
                    Log::error('Error también en disco de fallback', [
                        'error' => $fallbackError->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Error al guardar el archivo',
                        'error_code' => 'STORAGE_ERROR',
                    ], 500);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Archivo ACTIVOS.xlsx actualizado exitosamente',
                'file_path' => $storedPath,
                'file_size' => $file->getSize(),
                'rows_count' => count($data),
                'disk' => $disk,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al subir archivo ACTIVOS.xlsx', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor al procesar el archivo',
                'error_code' => 'UPLOAD_ERROR',
            ], 500);
        }
    }

    /**
     * Get information about the current ACTIVOS.xlsx file.
     */
    public function info(): JsonResponse
    {
        try {
            $filePath = self::ACTIVOS_FILE_PATH;
            $disk = self::S3_DISK;

            // Intentar leer desde bucket privado primero
            if (!Storage::disk($disk)->exists($filePath)) {
                // Si no existe en bucket privado, intentar disco local (desarrollo)
                $disk = self::FALLBACK_DISK;
                if (!Storage::disk($disk)->exists($filePath)) {
                    return response()->json([
                        'success' => true,
                        'exists' => false,
                        'message' => 'El archivo ACTIVOS.xlsx no existe',
                    ]);
                }
            }

            $fileInfo = [
                'success' => true,
                'exists' => true,
                'file_path' => $filePath,
                'disk' => $disk,
            ];

            // For private bucket, generate temporary URL if available
            try {
                if (self::S3_DISK === $disk) {
                    $storageDisk = Storage::disk($disk);
                    // Generate temporary URL for private bucket files (valid for 1 hour)
                    if (method_exists($storageDisk, 'temporaryUrl')) {
                        /** @phpstan-ignore-next-line - temporaryUrl() exists on S3-compatible drivers */
                        $fileInfo['temporary_url'] = $storageDisk->temporaryUrl($filePath, now()->addHours(1));
                        $fileInfo['url_expires_at'] = now()->addHours(1)->toIso8601String();
                        $fileInfo['url_source'] = 'temporary_url';
                    }
                }
            } catch (\Exception $e) {
                // URL not available for this disk type
                Log::debug('URL temporal no disponible para el disco', [
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);
            }

            // Obtener tamaño y fecha de modificación si está disponible
            try {
                $size = Storage::disk($disk)->size($filePath);
                $lastModified = Storage::disk($disk)->lastModified($filePath);

                $fileInfo['file_size'] = $size;
                $fileInfo['last_modified'] = date('c', $lastModified);
                $fileInfo['readable'] = true;
            } catch (\Exception $e) {
                Log::warning('No se pudo obtener metadata del archivo', [
                    'error' => $e->getMessage(),
                    'disk' => $disk,
                ]);
                $fileInfo['readable'] = false;
            }

            return response()->json($fileInfo);
        } catch (\Exception $e) {
            Log::error('Error al obtener información del archivo ACTIVOS', [
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
