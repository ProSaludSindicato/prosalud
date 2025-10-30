<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadActivosFileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;

class ActivosFileController extends Controller
{
    private const ACTIVOS_FILE_NAME = 'ACTIVOS.xlsx';
    private const ACTIVOS_FILE_PATH = 'data/' . self::ACTIVOS_FILE_NAME;
    private const S3_DISK = 'prosalud-public';
    private const FALLBACK_DISK = 'public';

    /**
     * Upload and replace the ACTIVOS2.xlsx file to S3
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
                        'error_code' => 'INVALID_EXCEL_FORMAT'
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
                    'error_code' => 'INVALID_EXCEL_FILE'
                ], 422);
            }
            
            $disk = self::S3_DISK;
            $storedPath = null;
            
            try {
                // Intentar subir a S3 público
                $storedPath = Storage::disk($disk)->putFileAs('data', $file, self::ACTIVOS_FILE_NAME);
                
                if ($storedPath === false) {
                    throw new \Exception('Failed to upload to S3');
                }
                
                Log::info('Archivo ACTIVOS2.xlsx subido a S3 exitosamente', [
                    'file_path' => $storedPath,
                    'file_size' => $file->getSize(),
                    'original_filename' => $file->getClientOriginalName(),
                    's3_disk' => $disk,
                    'uploaded_by' => Auth::check() ? Auth::id() : 'anonymous',
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString()
                ]);
                
            } catch (\Exception $e) {
                Log::warning('Error al subir a S3, intentando disco local como fallback', [
                    's3_error' => $e->getMessage(),
                    'fallback_disk' => self::FALLBACK_DISK,
                    'ip_address' => $request->ip(),
                ]);
                
                // Fallback a disco local si S3 falla (útil para desarrollo)
                try {
                    $disk = self::FALLBACK_DISK;
                    $storedPath = Storage::disk($disk)->putFileAs('data', $file, self::ACTIVOS_FILE_NAME);
                    
                    Log::info('Archivo ACTIVOS2.xlsx guardado en disco local (fallback)', [
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
                        'error_code' => 'STORAGE_ERROR'
                    ], 500);
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Archivo ACTIVOS2.xlsx actualizado exitosamente',
                'file_path' => $storedPath,
                'file_size' => $file->getSize(),
                'rows_count' => count($data),
                'disk' => $disk,
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error al subir archivo ACTIVOS2.xlsx', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor al procesar el archivo',
                'error_code' => 'UPLOAD_ERROR'
            ], 500);
        }
    }
    
    /**
     * Get information about the current ACTIVOS2.xlsx file
     */
    public function info(): JsonResponse
    {
        try {
            $filePath = self::ACTIVOS_FILE_PATH;
            $disk = self::S3_DISK;
            
            // Intentar leer desde S3 primero
            if (!Storage::disk($disk)->exists($filePath)) {
                // Si no existe en S3, intentar disco local (desarrollo)
                $disk = self::FALLBACK_DISK;
                if (!Storage::disk($disk)->exists($filePath)) {
                    return response()->json([
                        'success' => true,
                        'exists' => false,
                        'message' => 'El archivo ACTIVOS2.xlsx no existe'
                    ]);
                }
            }
            
            $fileInfo = [
                'success' => true,
                'exists' => true,
                'file_path' => $filePath,
                'disk' => $disk,
            ];
            
            // Try to get URL if available (prefer Laravel Cloud File Server if configured)
            try {
                if ($disk === self::S3_DISK) {
                    $cloudFileServerBase = env('LARAVEL_CLOUD_FILE_SERVER_URL');
                    if (!empty($cloudFileServerBase)) {
                        $fileInfo['url'] = rtrim($cloudFileServerBase, '/') . '/' . ltrim($filePath, '/');
                        $fileInfo['url_source'] = 'laravel_cloud_file_server';
                    } else {
                        /** @var \Illuminate\Filesystem\FilesystemAdapter $storageDisk */
                        $storageDisk = Storage::disk($disk);
                        $fileInfo['url'] = $storageDisk->url($filePath);
                        $fileInfo['url_source'] = 'storage_driver';
                    }
                }
            } catch (\Exception $e) {
                // URL not available for this disk type
                Log::debug('URL no disponible para el disco', [
                    'disk' => $disk,
                    'error' => $e->getMessage()
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
            Log::error('Error al obtener información del archivo ACTIVOS2', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del archivo',
                'error_code' => 'INFO_ERROR'
            ], 500);
        }
    }
}

