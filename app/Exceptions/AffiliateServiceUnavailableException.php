<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a single-affiliate lookup against ProSaNet fails and the only remaining option
 * would be an expensive full catalog resync (dozens of paginated HTTP requests, tens of seconds).
 *
 * That resync is reserved for scenarios that already need the full list (reports, bulk listings,
 * the dedicated backfill command) where the cost is expected and bounded. Interactive, single
 * affiliate actions (registering a delivery/return) should fail fast with an actionable message
 * instead of blocking the request for a minute and then possibly still failing.
 */
class AffiliateServiceUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'El servicio de afiliados no está disponible en este momento. Intenta nuevamente en unos minutos.')
    {
        parent::__construct($message);
    }
}
