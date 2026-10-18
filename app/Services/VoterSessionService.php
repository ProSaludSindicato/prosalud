<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class VoterSessionService
{
    public const HEADER = 'X-Voter-Token';

    private const TTL_MINUTES = 30;

    /**
     * Issue an encrypted, short-lived token that binds a validated voter to a scope.
     */
    public function issue(string $documentType, string $documentNumber, string $hospital, string $scope): string
    {
        return Crypt::encryptString(json_encode([
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'hospital' => $hospital,
            'scope' => $scope,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{document_type: string, document_number: string, hospital: string, scope: string}|null
     */
    public function verify(?string $token, string $scope): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['scope'] ?? null) !== $scope
            || (int) ($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        foreach (['document_type', 'document_number', 'hospital'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                return null;
            }
        }

        return [
            'document_type' => $payload['document_type'],
            'document_number' => $payload['document_number'],
            'hospital' => $payload['hospital'],
            'scope' => $payload['scope'],
        ];
    }

    public function candidateScope(string $electionKey): string
    {
        return 'candidate:'.$electionKey;
    }
}
