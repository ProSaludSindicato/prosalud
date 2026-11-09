<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Http\Requests\RevokeAssemblyProxyRequest;
use App\Models\Assembly;
use App\Models\AssemblyProxy;
use App\Services\AssemblyProxyService;
use App\Services\ExcelReaderService;
use App\Services\VoterSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AssemblyProxyController extends Controller
{
    public function __construct(
        private AssemblyProxyService $proxyService,
        private VoterSessionService $voterSessionService,
        private ExcelReaderService $excelReaderService,
    ) {}

    /**
     * The validated delegate declares they attend on behalf of the delegates assigned to them in the delegates file.
     */
    public function declare(Request $request): JsonResponse
    {
        $assembly = Assembly::getCurrent();
        $session = $assembly ? $this->voterSessionService->verifyAssemblyAttendee($request, $assembly->id) : null;

        if ($session === null) {
            return $this->invalidSessionResponse();
        }

        $representedDelegates = $this->excelReaderService->getAssemblyRepresentedDelegates($session['document_number'], $assembly);

        if ($representedDelegates === []) {
            return response()->json([
                'success' => false,
                'message' => 'Usted no figura como apoderado de ningún delegado en el listado de la asamblea.',
                'error_code' => 'NO_PROXIES_ASSIGNED',
            ], 422);
        }

        $result = $this->proxyService->registerDeclaredProxies(
            $assembly,
            $session['document_number'],
            $session['name'] !== '' ? $session['name'] : null,
            $representedDelegates,
            $request
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Delegates the current voter represents.
     */
    public function mine(Request $request): JsonResponse
    {
        $assembly = Assembly::getCurrent();
        $session = $assembly ? $this->voterSessionService->verifyAssemblyAttendee($request, $assembly->id) : null;

        if ($session === null) {
            return $this->invalidSessionResponse();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'registered' => $this->proxyService->activeRepresentedFor($assembly->id, $session['document_number']),
            ],
        ]);
    }

    /**
     * List the proxies of an assembly (defaults to the active one).
     */
    public function index(Request $request): JsonResponse
    {
        $assembly = $request->filled('assembly_id')
            ? Assembly::query()->find($request->input('assembly_id'))
            : Assembly::getCurrent();

        if ($assembly === null) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $proxies = $assembly->proxies()
            ->withCount('votes')
            ->orderBy('proxy_name')
            ->orderBy('represented_name')
            ->get()
            ->map(fn (AssemblyProxy $proxy): array => [
                'id' => $proxy->id,
                'proxyDocument' => $proxy->proxy_document,
                'proxyName' => $proxy->proxy_name,
                'representedDocument' => $proxy->represented_document,
                'representedName' => $proxy->represented_name,
                'status' => $proxy->status,
                'declaredAt' => $proxy->declared_at?->toISOString(),
                'revokedAt' => $proxy->revoked_at?->toISOString(),
                'revokedReason' => $proxy->revoked_reason,
                'votesCount' => $proxy->votes_count,
            ]);

        return response()->json(['success' => true, 'data' => $proxies]);
    }

    /**
     * Revoke a proxy so the representative stops voting on behalf of the delegate.
     */
    public function revoke(RevokeAssemblyProxyRequest $request, string $id): JsonResponse
    {
        $proxy = AssemblyProxy::query()->find($id);

        if ($proxy === null) {
            return response()->json([
                'success' => false,
                'message' => 'Poder no encontrado',
            ], 404);
        }

        if (! $proxy->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'El poder ya se encuentra revocado',
            ], 422);
        }

        $proxy->revoke($request->validated('reason') ?: 'Revocado por el administrador');

        Log::info('Poder de asamblea revocado por administrador', [
            'proxy_id' => $proxy->id,
            'assembly_id' => $proxy->assembly_id,
            'proxy_document' => $proxy->proxy_document,
            'represented_document' => $proxy->represented_document,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Poder revocado correctamente',
        ]);
    }

    private function invalidSessionResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Su sesión de votación no es válida o expiró. Valide nuevamente su documento.',
            'error_code' => 'VOTER_SESSION_INVALID',
        ], 401);
    }
}
