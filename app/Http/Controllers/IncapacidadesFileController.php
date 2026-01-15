<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadIncapacidadesFileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Auth, Log, Storage};
use PhpOffice\PhpSpreadsheet\{Exception as SpreadsheetException, IOFactory};
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncapacidadesFileController extends Controller
{
    private const FILE_NAME = 'RELACION_INCAPACIDADES.xlsx';
    private const FILE_PATH = 'data/' . self::FILE_NAME;
    private const STORAGE_DIRECTORY = 'data';
    private const PRIMARY_DISK = 'prosalud-private';
    private const FALLBACK_DISK = 'local';

    public function upload(UploadIncapacidadesFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');

            try {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getSheet(0);
                $data = $worksheet ? $worksheet->toArray() : [];

                if (count($data) < 2) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo Excel parece estar vacío o no tiene el formato esperado.',
                        'error_code' => 'INVALID_EXCEL_FORMAT',
                    ], 422);
                }
            } catch (SpreadsheetException $e) {
                Log::warning('Archivo de incapacidades inválido', [
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

            try {
                $storedPath = Storage::disk($disk)->putFileAs(self::STORAGE_DIRECTORY, $file, self::FILE_NAME);
                if (false === $storedPath) {
                    throw new \Exception('Error al subir archivo al bucket privado');
                }

                Log::info('Archivo de incapacidades subido a bucket privado', [
                    'file_path' => $storedPath,
                    'file_size' => $file->getSize(),
                    'original_filename' => $file->getClientOriginalName(),
                    'disk' => $disk,
                    'uploaded_by' => Auth::check() ? Auth::id() : 'anonymous',
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Exception $e) {
                Log::warning('Fallo al subir archivo de incapacidades a bucket privado, intentando fallback', [
                    'error' => $e->getMessage(),
                    'fallback_disk' => self::FALLBACK_DISK,
                    'ip_address' => $request->ip(),
                ]);

                try {
                    $disk = self::FALLBACK_DISK;
                    $storedPath = Storage::disk($disk)->putFileAs(self::STORAGE_DIRECTORY, $file, self::FILE_NAME);

                    if (false === $storedPath) {
                        throw new \Exception('Error al subir archivo al disco de fallback');
                    }

                    Log::info('Archivo de incapacidades guardado en disco local (fallback)', [
                        'file_path' => $storedPath,
                        'file_size' => $file->getSize(),
                        'disk' => $disk,
                    ]);
                } catch (\Exception $fallbackError) {
                    Log::error('Error al guardar archivo de incapacidades en dispositivos configurados', [
                        'error' => $fallbackError->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Error al guardar el archivo de incapacidades.',
                        'error_code' => 'STORAGE_ERROR',
                    ], 500);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Archivo de incapacidades actualizado exitosamente.',
                'file_path' => $storedPath,
                'file_size' => $file->getSize(),
                'disk' => $disk,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error inesperado al subir archivo de incapacidades', [
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
     * Download the incapacidades Excel file.
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
                        'message' => 'El archivo de incapacidades no existe',
                        'error_code' => 'FILE_NOT_FOUND',
                    ], 404);
                }
            }

            try {
                // Obtener el contenido del archivo
                $fileContent = Storage::disk($disk)->get($filePath);

                Log::info('Archivo de incapacidades descargado', [
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
                Log::error('Error al descargar archivo de incapacidades', [
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
            Log::error('Error inesperado al descargar archivo de incapacidades', [
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
     * Get information about the current incapacidades file.
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
                        'message' => 'El archivo de incapacidades no existe',
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
                Log::warning('No se pudo obtener metadata del archivo de incapacidades', [
                    'error' => $e->getMessage(),
                    'disk' => $disk,
                ]);
                $fileInfo['readable'] = false;
            }

            return response()->json($fileInfo);
        } catch (\Exception $e) {
            Log::error('Error al obtener información del archivo de incapacidades', [
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
