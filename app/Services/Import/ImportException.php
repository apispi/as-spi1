<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * A user-facing import problem: the message is shown verbatim, so it must
 * explain what to fix rather than what broke internally.
 */
class ImportException extends RuntimeException
{
}
