<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyAttendance;
use App\Models\AssemblyProxy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssemblyProxyService
{
    public const SKIP_ATTENDED_IN_PERSON = 'attended_in_person';

    public const SKIP_REPRESENTED_BY_OTHER = 'represented_by_other';

    public const SKIP_REVOKED = 'revoked';

    /**
     * Register the proxies a delegate declared when signing attendance, limited to the delegates
     * assigned to them in the delegates file.
     *
     * @param  list<array{cedula: string, nombre_apellidos: string}>  $representedDelegates
     * @return array{registered: list<array{document: string, name: string}>, skipped: list<array{document: string, name: string, reason: string, message: string}>}
     */
    public function registerDeclaredProxies(
        Assembly $assembly,
        string $proxyDocument,
        ?string $proxyName,
        array $representedDelegates,
        Request $request
    ): array {
        $registered = [];
        $skipped = [];

        foreach ($representedDelegates as $delegate) {
            $document = $delegate['cedula'];
            $name = $delegate['nombre_apellidos'];

            $attendedInPerson = AssemblyAttendance::query()
                ->where('assembly_id', $assembly->id)
                ->where('document_number', $document)
                ->exists();

            if ($attendedInPerson) {
                $skipped[] = $this->skipped($document, $name, self::SKIP_ATTENDED_IN_PERSON);

                continue;
            }

            $existing = AssemblyProxy::query()
                ->where('assembly_id', $assembly->id)
                ->where('represented_document', $document)
                ->first();

            if ($existing === null) {
                try {
                    $existing = AssemblyProxy::query()->create([
                        'assembly_id' => $assembly->id,
                        'proxy_document' => $proxyDocument,
                        'proxy_name' => $proxyName,
                        'represented_document' => $document,
                        'represented_name' => $name,
                        'status' => AssemblyProxy::STATUS_ACTIVE,
                        'declared_at' => now(),
                        'ip_address' => $request->ip(),
                        'user_agent' => Str::limit($request->userAgent() ?? '', 512, ''),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $existing = AssemblyProxy::query()
                        ->where('assembly_id', $assembly->id)
                        ->where('represented_document', $document)
                        ->firstOrFail();
                }

                Log::info('Poder de asamblea registrado', [
                    'assembly_id' => $assembly->id,
                    'proxy_id' => $existing->id,
                    'proxy_document' => $existing->proxy_document,
                    'represented_document' => $existing->represented_document,
                ]);
            }

            if ($existing->proxy_document !== $proxyDocument) {
                $skipped[] = $this->skipped($document, $name, self::SKIP_REPRESENTED_BY_OTHER);

                continue;
            }

            if (! $existing->isActive()) {
                $skipped[] = $this->skipped($document, $name, self::SKIP_REVOKED);

                continue;
            }

            $registered[] = ['document' => $document, 'name' => $name];
        }

        return ['registered' => $registered, 'skipped' => $skipped];
    }

    /**
     * Active proxies held by a delegate in an assembly.
     *
     * @return Collection<int, AssemblyProxy>
     */
    public function activeProxiesFor(int|string $assemblyId, string $proxyDocument): Collection
    {
        return AssemblyProxy::query()
            ->active()
            ->where('assembly_id', $assemblyId)
            ->where('proxy_document', $proxyDocument)
            ->orderBy('represented_name')
            ->get();
    }

    /**
     * Delegates currently represented by a proxy, for display to the voter.
     *
     * @return list<array{document: string, name: string}>
     */
    public function activeRepresentedFor(int|string $assemblyId, string $proxyDocument): array
    {
        return $this->activeProxiesFor($assemblyId, $proxyDocument)
            ->map(fn (AssemblyProxy $proxy): array => [
                'document' => $proxy->represented_document,
                'name' => (string) $proxy->represented_name,
            ])
            ->values()
            ->all();
    }

    /**
     * A delegate who attends in person takes back their vote: their active proxy, if any, is revoked.
     * Votes already cast by the proxy remain recorded.
     */
    public function revokeOnPersonalAttendance(Assembly $assembly, string $document): ?AssemblyProxy
    {
        $proxy = AssemblyProxy::query()
            ->active()
            ->where('assembly_id', $assembly->id)
            ->where('represented_document', $document)
            ->first();

        if ($proxy === null) {
            return null;
        }

        $proxy->revoke('El delegado asistió personalmente');

        Log::info('Poder de asamblea revocado por asistencia personal del representado', [
            'assembly_id' => $assembly->id,
            'proxy_id' => $proxy->id,
            'proxy_document' => $proxy->proxy_document,
            'represented_document' => $document,
        ]);

        return $proxy;
    }

    /**
     * @return array{document: string, name: string, reason: string, message: string}
     */
    private function skipped(string $document, string $name, string $reason): array
    {
        $message = match ($reason) {
            self::SKIP_ATTENDED_IN_PERSON => 'Ya registró su asistencia personalmente.',
            self::SKIP_REPRESENTED_BY_OTHER => 'Ya está representado por otro apoderado.',
            default => 'El poder fue revocado. Comuníquese con la mesa de la asamblea.',
        };

        return ['document' => $document, 'name' => $name, 'reason' => $reason, 'message' => $message];
    }
}
