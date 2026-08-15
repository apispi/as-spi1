<?php

namespace App\Services\Variables;

/**
 * Holds the secret values substituted into the current request so that
 * anything we persist (request history) or echo back (the resolved request
 * shown beside the response, and therefore any shared report) shows a mask
 * instead of the credential.
 *
 * Registered as a singleton, so it lives for exactly one HTTP request.
 */
class SecretMasker
{
    public const MASK = '••••••';

    /**
     * Very short secrets are not masked: replacing every "1" or "ok" in a
     * response would destroy the output for no real protection.
     */
    private const MIN_LENGTH = 4;

    /** @var string[] */
    private array $secrets = [];

    public function remember(array $values): void
    {
        foreach ($values as $value) {
            if (is_string($value) && strlen($value) >= self::MIN_LENGTH) {
                $this->secrets[] = $value;
            }
        }

        // Mask longer secrets first so a secret that contains another is not
        // partially replaced.
        usort($this->secrets, fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    public function hasSecrets(): bool
    {
        return $this->secrets !== [];
    }

    /**
     * Replace every remembered secret in a string, array, or scalar.
     */
    public function mask(mixed $value): mixed
    {
        if ($this->secrets === []) {
            return $value;
        }

        if (is_string($value)) {
            return str_replace($this->secrets, self::MASK, $value);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $newKey = is_string($key) ? $this->mask($key) : $key;
                $out[$newKey] = $this->mask($item);
            }

            return $out;
        }

        return $value;
    }
}
