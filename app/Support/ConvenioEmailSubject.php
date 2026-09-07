<?php

namespace App\Support;

use Carbon\CarbonInterface;

class ConvenioEmailSubject
{
    public static function make(
        string $documento,
        string $nombreConvenio,
        bool $isTest = false,
        ?CarbonInterface $sentAt = null,
    ): string {
        $sentAt ??= now();

        $subject = sprintf(
            'Convenio %s (%s) - %s - ProSalud',
            $documento,
            $nombreConvenio,
            $sentAt->timezone((string) config('app.timezone'))->format('d/m/Y H:i:s'),
        );

        if ($isTest) {
            return '[TEST] '.$subject;
        }

        return $subject;
    }

    public static function makeCompleted(
        string $documento,
        string $nombreConvenio,
        bool $isTest = false,
        ?CarbonInterface $sentAt = null,
    ): string {
        $sentAt ??= now();

        $subject = sprintf(
            'Convenio firmado %s (%s) - %s - ProSalud',
            $documento,
            $nombreConvenio,
            $sentAt->timezone((string) config('app.timezone'))->format('d/m/Y H:i:s'),
        );

        if ($isTest) {
            return '[TEST] '.$subject;
        }

        return $subject;
    }
}
