<?php

namespace App\Services\Security;

use RuntimeException;

/**
 * Thrown when the connection-time SSRF guard refuses to pin a host — because
 * it resolves to a private/reserved address, is a blocked internal name, or
 * cannot be resolved at all. Outbound clients let this propagate so the request
 * fails closed rather than connecting to an unvalidated address.
 */
class SsrfException extends RuntimeException
{
}
