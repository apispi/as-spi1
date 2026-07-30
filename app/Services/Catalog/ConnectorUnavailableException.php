<?php

namespace App\Services\Catalog;

use RuntimeException;

/**
 * Thrown when a connector cannot be reached or is the wrong type for the
 * requested action. Carries an HTTP status so controllers can translate it
 * directly to a JSON error response.
 */
class ConnectorUnavailableException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
