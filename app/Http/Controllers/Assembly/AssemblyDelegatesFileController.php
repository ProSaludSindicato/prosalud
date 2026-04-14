<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadAssemblyDelegatesFileRequest;
use App\Models\Assembly;
use App\Models\AssemblyDelegateFileVersion;
use App\Services\AssemblyDelegatesFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssemblyDelegatesFileController extends Controller
{
    private const PRIMARY_DISK = 'prosalud-private';

    private const FALLBACK_DISK = 'local';

    public function __construct(
        private AssemblyDelegatesFileService $delegatesFileService,
    ) {}

    public function show(string $id): JsonResponse
    {
        $assembly = Assembly::query()->findOrFail($id);
        $latest = $assembly->delegateFileVersions()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'hasFile' => $assembly->delegates_file_path !== null,
                'storagePath' => $assembly->delegates_file_path,
                'disk' => $assembly->delegates_file_disk,
                'latestVersion' => $latest ? $this->formatVersion($latest) : null,
            ],
        ]);
    }

    public function versions(string $id): JsonResponse
    {
        $assembly = Assembly::query()->findOrFail($id);
        $versions = $assembly->delegateFileVersions()
            ->with('uploadedByUser:id,name,email')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $versions->map(fn (AssemblyDelegateFileVersion $v) => $this->formatVersion($v)),
        ]);
    }

    public function upload(UploadAssemblyDelegatesFileRequest $request, string $id): JsonResponse
    {
        $assembly = Assembly::query()->findOrFail($id);
        $file = $request->file('file');

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $rowCount = $this->delegatesFileService->validateDelegatesSpreadsheet($spreadsheet);
        } catch (SpreadsheetException $e) {
            Log::warning('Excel de delegados inválido', [
                'assembly_id' => $assembly->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El archivo Excel no es válido o está corrupto.',
                'error_code' => 'INVALID_EXCEL_FILE',
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'INVALID_DELEGATES_STRUCTURE',
            ], 422);
        }

        $disk = self::PRIMARY_DISK;
        $relativePath = sprintf(
            'assembly-delegates/%s/%s.xlsx',
            $assembly->id,
            Str::uuid()->toString()
        );

        try {
            $storedPath = Storage::disk($disk)->putFileAs(
                dirname($relativePath),
                $file,
                basename($relativePath)
            );

            if ($storedPath === false) {
                throw new \RuntimeException('No se pudo guardar el archivo en el almacenamiento privado.');
            }
        } catch (\Exception $e) {
            Log::warning('Fallo al subir delegados a bucket privado, intentando disco local', [
                'error' => $e->getMessage(),
                'assembly_id' => $assembly->id,
            ]);

            $disk = self::FALLBACK_DISK;
            $storedPath = Storage::disk($disk)->putFileAs(
                dirname($relativePath),
                $file,
                basename($relativePath)
            );

            if ($storedPath === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al guardar el archivo.',
                    'error_code' => 'STORAGE_ERROR',
                ], 500);
            }
        }

        $version = AssemblyDelegateFileVersion::query()->create([
            'assembly_id' => $assembly->id,
            'storage_path' => $storedPath,
            'disk' => $disk,
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by' => Auth::id(),
            'row_count' => $rowCount,
        ]);

        $assembly->update([
            'delegates_file_path' => $storedPath,
            'delegates_file_disk' => $disk,
        ]);

        Log::info('Archivo de delegados de asamblea actualizado', [
            'assembly_id' => $assembly->id,
            'version_id' => $version->id,
            'path' => $storedPath,
            'disk' => $disk,
            'rows' => $rowCount,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lista de delegados actualizada correctamente.',
            'data' => [
                'version' => $this->formatVersion($version->fresh(['uploadedByUser'])),
                'rowCount' => $rowCount,
            ],
        ], 201);
    }

    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $assembly = Assembly::query()->findOrFail($id);
        $versionId = $request->query('version');
        if ($versionId) {
            $version = AssemblyDelegateFileVersion::query()
                ->where('assembly_id', $assembly->id)
                ->whereKey($versionId)
                ->first();
            if (! $version) {
                return response()->json([
                    'success' => false,
                    'message' => 'Versión no encontrada.',
                ], 404);
            }
            $path = $version->storage_path;
            $disk = $version->disk;
            $downloadName = $version->original_filename ?? 'delegados-asamblea-'.$assembly->id.'-v'.$version->id.'.xlsx';
        } else {
            if (! $assembly->delegates_file_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay archivo de delegados cargado para esta asamblea.',
                ], 404);
            }
            $path = $assembly->delegates_file_path;
            $disk = $assembly->delegates_file_disk ?: self::PRIMARY_DISK;
            $downloadName = 'delegados-asamblea-'.$assembly->id.'.xlsx';
        }

        if (! Storage::disk($disk)->exists($path)) {
            $alt = $disk === self::PRIMARY_DISK ? self::FALLBACK_DISK : self::PRIMARY_DISK;
            if (Storage::disk($alt)->exists($path)) {
                $disk = $alt;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo ya no está disponible en el almacenamiento.',
                ], 404);
            }
        }

        return Storage::disk($disk)->download($path, $downloadName);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatVersion(AssemblyDelegateFileVersion $version): array
    {
        $uploader = $version->relationLoaded('uploadedByUser') ? $version->uploadedByUser : $version->uploadedByUser()->first();

        return [
            'id' => $version->id,
            'storagePath' => $version->storage_path,
            'disk' => $version->disk,
            'originalFilename' => $version->original_filename,
            'rowCount' => $version->row_count,
            'uploadedBy' => $uploader ? [
                'id' => $uploader->id,
                'name' => $uploader->name,
                'email' => $uploader->email,
            ] : null,
            'createdAt' => $version->created_at->toISOString(),
        ];
    }
}
