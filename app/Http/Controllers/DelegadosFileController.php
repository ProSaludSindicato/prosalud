<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadDelegadosFileRequest;
use App\Http\Requests\UploadDelegadosPhotosZipRequest;
use App\Services\ExcelReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DelegadosFileController extends Controller
{
    public function __construct(
        private readonly ExcelReaderService $excelReaderService,
    ) {}

    private const FILE_NAME = 'DELEGADOS.xlsx';

    private const STORAGE_DIRECTORY = 'data';

    private const FILE_PATH = self::STORAGE_DIRECTORY.'/'.self::FILE_NAME;

    private const PRIMARY_DISK = 'prosalud-private';

    private const FALLBACK_DISK = 'local';

    private const CANDIDATES_AVATARS_DIRECTORY = 'delegados/avatars';

    private const PUBLIC_DISK = 'prosalud-public';

    private const PUBLIC_FALLBACK_DISK = 'public';

    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    public function upload(UploadDelegadosFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');

            try {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getSheet(0);
                $data = $worksheet ? $worksheet->toArray() : [];

                $validationError = $this->excelReaderService->validateDelegadosUploadSheetData($data);
                if ($validationError !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $validationError,
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

    public function uploadPhotosZip(UploadDelegadosPhotosZipRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $zip = new \ZipArchive;
        $openResult = $zip->open($file->getRealPath());
        if ($openResult !== true) {
            return response()->json([
                'success' => false,
                'message' => 'No fue posible abrir el archivo ZIP.',
                'error_code' => 'INVALID_ZIP_FILE',
            ], 422);
        }

        try {
            $entriesByCedula = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);
                if (! is_string($entryName)) {
                    continue;
                }

                if (str_ends_with($entryName, '/')) {
                    continue;
                }

                $filename = basename($entryName);
                if ($filename === '' || str_starts_with($filename, '.')) {
                    continue;
                }

                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (! in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
                    continue;
                }

                $nameWithoutExtension = trim(pathinfo($filename, PATHINFO_FILENAME));
                if ($nameWithoutExtension === '' || ! ctype_digit($nameWithoutExtension)) {
                    continue;
                }

                $entriesByCedula[$nameWithoutExtension] = [
                    'entry_name' => $entryName,
                    'extension' => $extension,
                ];
            }

            if (empty($entriesByCedula)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El ZIP no contiene imágenes válidas con nombre de cédula (.jpg, .jpeg, .png).',
                    'error_code' => 'NO_VALID_IMAGES',
                ], 422);
            }

            $disk = self::PUBLIC_DISK;
            try {
                $storedCount = $this->replaceCandidatePhotosOnDisk($zip, $entriesByCedula, $disk);
            } catch (\Throwable $primaryError) {
                Log::warning('Fallo al actualizar fotos de candidatos en bucket público, intentando fallback', [
                    'error' => $primaryError->getMessage(),
                    'fallback_disk' => self::PUBLIC_FALLBACK_DISK,
                    'ip_address' => $request->ip(),
                ]);

                $disk = self::PUBLIC_FALLBACK_DISK;
                $storedCount = $this->replaceCandidatePhotosOnDisk($zip, $entriesByCedula, $disk);
            }

            return response()->json([
                'success' => true,
                'message' => 'Fotos de candidatos actualizadas exitosamente.',
                'disk' => $disk,
                'stored_photos_count' => $storedCount,
                'accepted_extensions' => self::ALLOWED_IMAGE_EXTENSIONS,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error inesperado al actualizar fotos de candidatos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'uploaded_by' => Auth::check() ? Auth::id() : 'anonymous',
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error inesperado al actualizar fotos de candidatos.',
                'error_code' => 'UNEXPECTED_ERROR',
            ], 500);
        } finally {
            $zip->close();
        }
    }

    /**
     * Download the current delegados (candidatos) Excel file.
     */
    public function download(): StreamedResponse|JsonResponse
    {
        try {
            $filePath = self::FILE_PATH;
            $disk = self::PRIMARY_DISK;

            if (! Storage::disk($disk)->exists($filePath)) {
                $disk = self::FALLBACK_DISK;
                if (! Storage::disk($disk)->exists($filePath)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo de delegados no existe',
                        'error_code' => 'FILE_NOT_FOUND',
                    ], 404);
                }
            }

            try {
                $fileContent = Storage::disk($disk)->get($filePath);

                Log::info('Archivo de delegados descargado', [
                    'file_path' => $filePath,
                    'disk' => $disk,
                    'downloaded_by' => Auth::check() ? Auth::id() : 'anonymous',
                    'timestamp' => now()->toISOString(),
                ]);

                return response()->streamDownload(function () use ($fileContent) {
                    echo $fileContent;
                }, self::FILE_NAME, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="'.self::FILE_NAME.'"',
                ]);
            } catch (\Exception $e) {
                Log::error('Error al descargar archivo de delegados', [
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
            Log::error('Error inesperado al descargar archivo de delegados', [
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

    private function replaceCandidatePhotosOnDisk(\ZipArchive $zip, array $entriesByCedula, string $disk): int
    {
        Storage::disk($disk)->deleteDirectory(self::CANDIDATES_AVATARS_DIRECTORY);
        Storage::disk($disk)->makeDirectory(self::CANDIDATES_AVATARS_DIRECTORY);

        $storedCount = 0;
        foreach ($entriesByCedula as $cedula => $entryData) {
            $entryName = $entryData['entry_name'];
            $extension = $entryData['extension'];
            $imageContent = $zip->getFromName($entryName);
            if ($imageContent === false) {
                continue;
            }

            $path = self::CANDIDATES_AVATARS_DIRECTORY.'/'.$cedula.'.'.$extension;
            $stored = Storage::disk($disk)->put($path, $imageContent, [
                'visibility' => 'public',
                'ContentType' => match ($extension) {
                    'png' => 'image/png',
                    default => 'image/jpeg',
                },
            ]);

            if ($stored === false) {
                throw new \RuntimeException("No se pudo guardar la foto {$path} en {$disk}.");
            }

            $storedCount++;
        }

        if ($storedCount === 0) {
            throw new \RuntimeException("No se pudo almacenar ninguna foto de candidatos en {$disk}.");
        }

        Log::info('Fotos de candidatos actualizadas', [
            'disk' => $disk,
            'stored_photos_count' => $storedCount,
            'directory' => self::CANDIDATES_AVATARS_DIRECTORY,
            'timestamp' => now()->toISOString(),
        ]);

        return $storedCount;
    }
}
