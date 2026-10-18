<?php

namespace App\Services;

use Illuminate\Http\Request;

class RateLimitIdentity
{
    /**
     * Request input names that carry the document of the person being authenticated or queried.
     *
     * @var list<string>
     */
    private const DOCUMENT_FIELDS = [
        'documento',
        'numero_documento',
        'documento_afiliado',
        'document_number',
        'id_number',
        'voter.documentNumber',
    ];

    /**
     * Normalized document of the identity the request targets, or null when none is present.
     */
    public static function document(Request $request): ?string
    {
        foreach (self::DOCUMENT_FIELDS as $field) {
            $value = $request->input($field);

            if (is_scalar($value)) {
                $normalized = preg_replace('/[^0-9a-z]/', '', strtolower((string) $value));

                if ($normalized !== null && $normalized !== '') {
                    return $normalized;
                }
            }
        }

        return null;
    }

    public static function documentKey(string $prefix, string $document): string
    {
        return $prefix.':doc:'.md5($document);
    }
}
