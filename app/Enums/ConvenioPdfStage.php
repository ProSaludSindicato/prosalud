<?php

namespace App\Enums;

enum ConvenioPdfStage: string
{
    case Original = 'original';
    case FirmadoAfiliado = 'firmado-afiliado';
    case Final = 'final';

    public function fileName(): string
    {
        return $this->value.'.pdf';
    }
}
