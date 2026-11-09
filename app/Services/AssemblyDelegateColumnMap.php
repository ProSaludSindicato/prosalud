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
     * @param  int|null  $apoderadoCedula  Optional "CC APODERADO" column: document of the person representing the delegate of the row.
     * @param  int|null  $apoderadoNombre  Optional "APODERADO" column: name of that person.
     * @param  int|null  $apoderadoFechaExpedicion  Optional "FECHA EXPE APODERADO" column, used to authenticate a proxy who is not a delegate.
     */
    public function __construct(
        public int $cedula,
        public int $nombreApellidos,
        public ?int $sede,
        public int $estadoBd,
        public ?int $proceso,
        public int $fechaExpedicion,
        public ?int $apoderadoCedula = null,
        public ?int $apoderadoNombre = null,
        public ?int $apoderadoFechaExpedicion = null,
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
            $this->apoderadoCedula ?? 0,
            $this->apoderadoNombre ?? 0,
            $this->apoderadoFechaExpedicion ?? 0,
        );
    }

    public function isLegacyFourColumnLayout(): bool
    {
        return $this->sede === null && $this->proceso === null;
    }

    public function hasProxyColumns(): bool
    {
        return $this->apoderadoCedula !== null;
    }
}
