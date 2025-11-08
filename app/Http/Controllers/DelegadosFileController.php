<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadDelegadosFileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DelegadosFileController extends Controller
{
    private const FILE_NAME = 'DELEGADOS.xlsx';
    private const STORAGE_DIRECTORY = 'data';
    private const PRIMARY_DISK = 'prosalud-private';
    private const FALLBACK_DISK = 'local';

    public function upload(UploadDelegadosFileRequest $request): JsonResponse
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
                Log::warning('Archivo de delegados inválido', [
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

            try {
                $storedPath = Storage::disk($disk)->putFileAs(self::STORAGE_DIRECTORY, $file, self::FILE_NAME);
                if ($storedPath === false) {
                    throw new \Exception('Error al subir archivo al bucket privado');
                }

                Log::info('Archivo de delegados subido a bucket privado', [
                    'file_path' => $storedPath,
                    'file_size' => $file->getSize(),
                    'original_filename' => $file->getClientOriginalName(),
                    'disk' => $disk,
                    'uploaded_by' => Auth::check() ? Auth::id() : 'anonymous',
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Exception $e) {
                Log::warning('Fallo al subir archivo de delegados a bucket privado, intentando fallback', [
                    'error' => $e->getMessage(),
                    'fallback_disk' => self::FALLBACK_DISK,
                    'ip_address' => $request->ip(),
                ]);

                try {
                    $disk = self::FALLBACK_DISK;
                    $storedPath = Storage::disk($disk)->putFileAs(self::STORAGE_DIRECTORY, $file, self::FILE_NAME);

                    if ($storedPath === false) {
                        throw new \Exception('Error al subir archivo al disco de fallback');
                    }

                    Log::info('Archivo de delegados guardado en disco local (fallback)', [
                        'file_path' => $storedPath,
                        'file_size' => $file->getSize(),
                        'disk' => $disk,
                    ]);
                } catch (\Exception $fallbackError) {
                    Log::error('Error al guardar archivo de delegados en dispositivos configurados', [
                        'error' => $fallbackError->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Error al guardar el archivo de delegados.',
                        'error_code' => 'STORAGE_ERROR',
                    ], 500);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Archivo de delegados actualizado exitosamente.',
                'file_path' => $storedPath,
                'file_size' => $file->getSize(),
                'disk' => $disk,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error inesperado al subir archivo de delegados', [
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
}

