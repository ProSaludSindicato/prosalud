<?php

namespace App\Services;

class ObfuscationService
{
    /**
     * Obfuscate an address based on its length
     * 
     * Short: "Calle 123 #45-67" → "Calle***45-67"
     * Medium: "Calle 123 #45-67, Barrio Centro" → "Calle 12***Centro"
     * Long: "Calle 123 #45-67, Barrio Centro, Medellín, Antioquia" → "Calle 123 #4***Medellín, Antioquia"
     */
    public function obfuscateAddress(?string $address): ?string
    {
        if (empty($address)) {
            return $address;
        }

        $trimmed = trim($address);
        $length = strlen($trimmed);
        $parts = explode(',', $trimmed);

        if ($length <= 20) {
            // Short address: "Calle 123 #45-67" → "Calle***45-67"
            // Extract the street name (first word) and the number part at the end
            if (preg_match('/^([A-Za-zÁÉÍÓÚáéíóúÑñ\s]+?)(?:\s+\d+)?\s*#?(\d+[-\d]*)$/', $trimmed, $matches)) {
                $streetName = trim($matches[1]);
                $numberPart = $matches[2];
                return $streetName . '***' . $numberPart;
            }
            // Fallback: if no number pattern found, show first word and last 5 chars
            $words = preg_split('/\s+/', $trimmed);
            if (count($words) > 0) {
                $firstWord = $words[0];
                $lastPart = substr($trimmed, -5);
                return $firstWord . '***' . $lastPart;
            }
            // Final fallback: show first 5 chars and last 5 chars
            if ($length <= 10) {
                return substr($trimmed, 0, 5) . '***';
            }
            return substr($trimmed, 0, 5) . '***' . substr($trimmed, -5);
        } elseif ($length <= 40) {
            // Medium address: "Calle 123 #45-67, Barrio Centro" → "Calle 12***Centro"
            if (count($parts) >= 2) {
                $firstPart = trim($parts[0]);
                $lastPart = trim($parts[count($parts) - 1]);
                // Keep first 8 chars of first part
                $firstObfuscated = substr($firstPart, 0, min(8, strlen($firstPart)));
                return $firstObfuscated . '***' . $lastPart;
            }
            // Fallback: show first 8 chars and last 8 chars
            return substr($trimmed, 0, 8) . '***' . substr($trimmed, -8);
        } else {
            // Long address: "Calle 123 #45-67, Barrio Centro, Medellín, Antioquia" → "Calle 123 #4***Medellín, Antioquia"
            if (count($parts) >= 3) {
                $firstPart = trim($parts[0]);
                $lastTwoParts = trim($parts[count($parts) - 2]) . ', ' . trim($parts[count($parts) - 1]);
                // Keep first 12 chars of first part
                $firstObfuscated = substr($firstPart, 0, min(12, strlen($firstPart)));
                return $firstObfuscated . '***' . $lastTwoParts;
            } elseif (count($parts) >= 2) {
                $firstPart = trim($parts[0]);
                $lastPart = trim($parts[count($parts) - 1]);
                $firstObfuscated = substr($firstPart, 0, min(12, strlen($firstPart)));
                return $firstObfuscated . '***' . $lastPart;
            }
            // Fallback: show first 12 chars and last 20 chars
            return substr($trimmed, 0, 12) . '***' . substr($trimmed, -20);
        }
    }

    /**
     * Obfuscate a phone number
     * "3001234567" → "300****567"
     */
    public function obfuscatePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return $phone;
        }

        $trimmed = trim($phone);
        $length = strlen($trimmed);

        if ($length <= 3) {
            return str_repeat('*', $length);
        } elseif ($length <= 7) {
            // For short numbers, show first 2 and last 2
            return substr($trimmed, 0, 2) . str_repeat('*', $length - 4) . substr($trimmed, -2);
        } else {
            // Standard: show first 3 and last 3
            return substr($trimmed, 0, 3) . str_repeat('*', $length - 6) . substr($trimmed, -3);
        }
    }

    /**
     * Obfuscate an email address
     * "juan.perez@correo.com" → "ju**ez@correo.com"
     * "ejemplo@correo.com" → "ej**lo@correo.com"
     */
    public function obfuscateEmail(?string $email): ?string
    {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $localLength = strlen($localPart);

        if ($localLength <= 2) {
            $obfuscatedLocal = str_repeat('*', $localLength);
        } else {
            // Always show first 2 chars, then 2 asterisks, then last 2 chars
            $obfuscatedLocal = substr($localPart, 0, 2) . '**' . substr($localPart, -2);
        }

        return $obfuscatedLocal . '@' . $domain;
    }

    /**
     * Obfuscate an account number
     * "1234567890" → "12****7890"
     */
    public function obfuscateAccount(?string $account): ?string
    {
        if (empty($account)) {
            return $account;
        }

        $trimmed = trim($account);
        $length = strlen($trimmed);

        if ($length <= 2) {
            return str_repeat('*', $length);
        } elseif ($length <= 6) {
            // For short accounts, show first 1 and last 1
            return substr($trimmed, 0, 1) . str_repeat('*', $length - 2) . substr($trimmed, -1);
        } else {
            // Standard: show first 2 and last 4
            return substr($trimmed, 0, 2) . str_repeat('*', $length - 6) . substr($trimmed, -4);
        }
    }

    /**
     * Obfuscate affiliate data according to security requirements
     */
    public function obfuscateAfiliadoData(array $afiliadoData): array
    {
        $obfuscated = $afiliadoData;

        // Obfuscate address
        if (isset($obfuscated['direccion'])) {
            $obfuscated['direccion'] = $this->obfuscateAddress($obfuscated['direccion']);
        }

        // Obfuscate phone
        if (isset($obfuscated['telefono'])) {
            $obfuscated['telefono'] = $this->obfuscatePhone($obfuscated['telefono']);
        }

        // Obfuscate celular
        if (isset($obfuscated['celular'])) {
            $obfuscated['celular'] = $this->obfuscatePhone($obfuscated['celular']);
        }

        // Obfuscate email
        if (isset($obfuscated['correo_personal'])) {
            $obfuscated['correo_personal'] = $this->obfuscateEmail($obfuscated['correo_personal']);
        }

        // Obfuscate account number
        if (isset($obfuscated['numero_cuenta'])) {
            $obfuscated['numero_cuenta'] = $this->obfuscateAccount($obfuscated['numero_cuenta']);
        }

        return $obfuscated;
    }
}

