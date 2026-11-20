<?php

namespace App\Services\Integration\OneC;

use RuntimeException;

/**
 * Raised when a page of the 1С CounterpartiesExchange SOAP response could not
 * be retrieved or parsed. Lets the sync service tell a partial import from a
 * finished one.
 */
class OneCSoapException extends RuntimeException
{
}
