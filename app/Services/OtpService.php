<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OtpService
{
    private const OTP_LENGTH = 6;
    private const OTP_EXPIRY_MINUTES = 10;
    private const MAX_ATTEMPTS = 3;
    private const ATTEMPT_WINDOW_MINUTES = 15;
    private const RATE_LIMIT_WINDOW_MINUTES = 1;

    /**
     * Generate a 6-digit OTP code
     */
    public function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Store OTP for a specific identifier (document number)
     */
    public function storeOtp(string $identifier, string $otp): string
    {
        $sessionId = Str::uuid()->toString();
        $cacheKey = $this->getOtpCacheKey($identifier, $sessionId);
        
        Cache::put($cacheKey, [
            'otp' => $otp,
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ], now()->addMinutes(self::OTP_EXPIRY_MINUTES));

        // Store session mapping for cleanup
        $sessionKey = $this->getSessionCacheKey($identifier);
        $sessions = Cache::get($sessionKey, []);
        $sessions[] = [
            'session_id' => $sessionId,
            'created_at' => now()->toIso8601String(),
        ];
        Cache::put($sessionKey, $sessions, now()->addMinutes(self::OTP_EXPIRY_MINUTES + 5));

        Log::info('OTP almacenado para afiliado', [
            'identifier' => $identifier,
            'session_id' => $sessionId,
            'expires_in_minutes' => self::OTP_EXPIRY_MINUTES,
        ]);

        return $sessionId;
    }

    /**
     * Verify OTP code
     */
    public function verifyOtp(string $identifier, string $sessionId, string $otp): bool
    {
        $cacheKey = $this->getOtpCacheKey($identifier, $sessionId);
        $storedData = Cache::get($cacheKey);

        if (!$storedData) {
            Log::warning('Intento de verificación OTP con sesión inválida o expirada', [
                'identifier' => $identifier,
                'session_id' => $sessionId,
            ]);
            return false;
        }

        // Check attempts
        if ($storedData['attempts'] >= self::MAX_ATTEMPTS) {
            Log::warning('Máximo de intentos OTP excedido', [
                'identifier' => $identifier,
                'session_id' => $sessionId,
                'attempts' => $storedData['attempts'],
            ]);
            Cache::forget($cacheKey);
            return false;
        }

        // Increment attempts
        $storedData['attempts']++;
        Cache::put($cacheKey, $storedData, now()->addMinutes(self::OTP_EXPIRY_MINUTES));

        // Verify OTP
        if ($storedData['otp'] !== $otp) {
            Log::warning('OTP incorrecto', [
                'identifier' => $identifier,
                'session_id' => $sessionId,
                'attempt' => $storedData['attempts'],
            ]);
            return false;
        }

        // OTP is valid - mark as verified and extend expiry slightly
        $storedData['verified'] = true;
        Cache::put($cacheKey, $storedData, now()->addMinutes(5));

        Log::info('OTP verificado exitosamente', [
            'identifier' => $identifier,
            'session_id' => $sessionId,
        ]);

        return true;
    }

    /**
     * Check if OTP session is verified
     */
    public function isOtpVerified(string $identifier, string $sessionId): bool
    {
        $cacheKey = $this->getOtpCacheKey($identifier, $sessionId);
        $storedData = Cache::get($cacheKey);

        return $storedData && ($storedData['verified'] ?? false);
    }

    /**
     * Invalidate OTP session (after successful use)
     */
    public function invalidateOtp(string $identifier, string $sessionId): void
    {
        $cacheKey = $this->getOtpCacheKey($identifier, $sessionId);
        Cache::forget($cacheKey);

        Log::info('OTP invalidado', [
            'identifier' => $identifier,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Check rate limiting for OTP requests
     */
    public function checkRateLimit(string $identifier): bool
    {
        $rateLimitKey = $this->getRateLimitCacheKey($identifier);
        $requests = Cache::get($rateLimitKey, 0);

        if ($requests >= 1) {
            Log::warning('Rate limit excedido para solicitud OTP', [
                'identifier' => $identifier,
                'requests' => $requests,
            ]);
            return false;
        }

        Cache::put($rateLimitKey, $requests + 1, now()->addMinutes(self::RATE_LIMIT_WINDOW_MINUTES));
        return true;
    }

    /**
     * Get OTP cache key
     */
    private function getOtpCacheKey(string $identifier, string $sessionId): string
    {
        return "otp:{$identifier}:{$sessionId}";
    }

    /**
     * Get session cache key
     */
    private function getSessionCacheKey(string $identifier): string
    {
        return "otp:sessions:{$identifier}";
    }

    /**
     * Get rate limit cache key
     */
    private function getRateLimitCacheKey(string $identifier): string
    {
        return "otp:ratelimit:{$identifier}";
    }

    /**
     * Clean up old OTP sessions for an identifier
     */
    public function cleanupOldSessions(string $identifier): void
    {
        $sessionKey = $this->getSessionCacheKey($identifier);
        $sessions = Cache::get($sessionKey, []);
        
        $validSessions = [];
        foreach ($sessions as $session) {
            $createdAt = \Carbon\Carbon::parse($session['created_at']);
            if ($createdAt->addMinutes(self::OTP_EXPIRY_MINUTES + 5)->isFuture()) {
                $validSessions[] = $session;
            }
        }
        
        if (empty($validSessions)) {
            Cache::forget($sessionKey);
        } else {
            Cache::put($sessionKey, $validSessions, now()->addMinutes(self::OTP_EXPIRY_MINUTES + 5));
        }
    }
}

