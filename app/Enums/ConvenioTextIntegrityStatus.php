<?php

namespace App\Enums;

enum ConvenioTextIntegrityStatus: string
{
    case Matched = 'matched';
    case Unavailable = 'unavailable';
}
