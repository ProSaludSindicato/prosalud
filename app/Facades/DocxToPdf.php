<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array convert(string $docxPath, bool $saveToStorage = true)
 *
 * @see \App\Services\DocxToPdfCloudConvertService
 */
class DocxToPdf extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\DocxToPdfCloudConvertService::class;
    }
}

