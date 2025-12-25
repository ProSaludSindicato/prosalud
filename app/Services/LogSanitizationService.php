<?php

namespace App\Services;

class LogSanitizationService
{
    /**
     * Sanitize an array of log data by ofuscating sensitive information.
     *
     * @param array $data The log data to sanitize
     * @return array The sanitized log data
     */
    public static function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            // Skip sensitive keys entirely or sanitize them
            if (self::isSensitiveKey($lowerKey)) {
                $sanitized[$key] = self::sanitizeValue($key, $value);
            } elseif (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$key] = self::sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if a key represents sensitive information.
     *
     * @param string $key The key to check (should be lowercase)
     * @return bool
     */
    private static function isSensitiveKey(string $key): bool
    {
        $sensitivePatterns = [
            'documento',
            'fecha_expedicion',
            'fecha_expedición',
            'correo',
            'email',
            'password',
            'token',
            'api_key',
            'apikey',
            'secret',
            'authorization',
            'bearer',
            'header',
            'headers',
            'otp',
            'session_id',
            'sessionid',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize a sensitive value based on its key.
     *
     * @param string $key The key name
     * @param mixed $value The value to sanitize
     * @return string|array|null
     */
    private static function sanitizeValue(string $key, $value): string|array|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $lowerKey = strtolower($key);
        $stringValue = (string) $value;

        // Documento: Show only last 4 digits + hash
        if (str_contains($lowerKey, 'documento')) {
            if (strlen($stringValue) <= 4) {
                return '****';
            }
            $last4 = substr($stringValue, -4);
            $hash = substr(hash('sha256', $stringValue), 0, 8);
            return "****{$last4} ({$hash})";
        }

        // Fecha de expedición: Show only year
        if (str_contains($lowerKey, 'fecha_expedicion') || str_contains($lowerKey, 'fecha_expedición')) {
            // Try to extract year if it's a date string
            if (preg_match('/(\d{4})/', $stringValue, $matches)) {
                return "****-{$matches[1]}";
            }
            return '****';
        }

        // Email: Obfuscate email address
        if (str_contains($lowerKey, 'correo') || str_contains($lowerKey, 'email')) {
            return self::obfuscateEmail($stringValue);
        }

        // Token/Bearer/Authorization: Show only length and hash
        if (str_contains($lowerKey, 'token') ||
            str_contains($lowerKey, 'bearer') ||
            str_contains($lowerKey, 'authorization')) {
            $length = strlen($stringValue);
            $hash = substr(hash('sha256', $stringValue), 0, 8);
            return "[REDACTED: length={$length}, hash={$hash}]";
        }

        // API Key: Show only length and hash
        if (str_contains($lowerKey, 'api_key') || str_contains($lowerKey, 'apikey')) {
            $length = strlen($stringValue);
            $hash = substr(hash('sha256', $stringValue), 0, 8);
            return "[REDACTED: length={$length}, hash={$hash}]";
        }

        // OTP: Show only length
        if (str_contains($lowerKey, 'otp')) {
            return '[REDACTED: OTP]';
        }

        // Session ID: Show only hash
        if (str_contains($lowerKey, 'session')) {
            $hash = substr(hash('sha256', $stringValue), 0, 12);
            return "[REDACTED: session_hash={$hash}]";
        }

        // Headers: Remove sensitive headers
        if (str_contains($lowerKey, 'header') && is_array($value)) {
            return self::sanitizeHeaders($value);
        }

        // Password: Always redact
        if (str_contains($lowerKey, 'password')) {
            return '[REDACTED]';
        }

        // Default: Show only hash for unknown sensitive values
        $hash = substr(hash('sha256', $stringValue), 0, 8);
        return "[REDACTED: hash={$hash}]";
    }

    /**
     * Obfuscate an email address.
     *
     * @param string $email
     * @return string
     */
    private static function obfuscateEmail(string $email): string
    {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '[REDACTED: invalid_email]';
        }

        [$localPart, $domain] = explode('@', $email, 2);

        // Obfuscate local part
        $localLength = strlen($localPart);
        if ($localLength <= 2) {
            $obfuscatedLocal = '***';
        } else {
            $first = substr($localPart, 0, 1);
            $last = substr($localPart, -1);
            $obfuscatedLocal = $first . '***' . $last;
        }

        return $obfuscatedLocal . '@' . $domain;
    }

    /**
     * Sanitize HTTP headers by removing sensitive ones.
     *
     * @param array $headers
     * @return array
     */
    private static function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaderKeys = [
            'authorization',
            'cookie',
            'x-api-key',
            'x-auth-token',
            'bearer',
        ];

        $sanitized = [];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);

            // Skip sensitive headers entirely
            $isSensitive = false;
            foreach ($sensitiveHeaderKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeHeaders($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}

