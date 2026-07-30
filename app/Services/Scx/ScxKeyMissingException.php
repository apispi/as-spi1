<?php

namespace App\Services\Scx;

use RuntimeException;

/**
 * Thrown when an AI-powered action is attempted by a user who has not yet
 * configured their SCX API key. Controllers translate this to a 400.
 */
class ScxKeyMissingException extends RuntimeException
{
}
