<?php

namespace App\Services;

/**
 * Column indices for an assembly delegates spreadsheet (0-based).
 */
readonly class AssemblyDelegateColumnMap
{
    /**
     * @param  int|null  $sede  Null when using the legacy four-column layout.
     * @param  int|null  $proceso  Null when using the legacy four-column layout.
     */
    public function __construct(
        public int $cedula,
        public int $nombreApellidos,
        public ?int $sede,
        public int $estadoBd,
        public ?int $proceso,
        public int $fechaExpedicion,
    ) {}

    public function maxColumnIndex(): int
    {
        return max(
            $this->cedula,
            $this->nombreApellidos,
            $this->estadoBd,
            $this->fechaExpedicion,
            $this->sede ?? 0,
            $this->proceso ?? 0,
        );
    }

    public function isLegacyFourColumnLayout(): bool
    {
        return $this->sede === null && $this->proceso === null;
    }
}
