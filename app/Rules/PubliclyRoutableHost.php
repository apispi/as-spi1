<?php

namespace App\Rules;

use App\Rules\Concerns\ChecksHostRoutability;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The bare-host counterpart to PubliclyRoutableUrl, for the MQTT/AMQP/gRPC
 * testers where the target is a hostname or IP (the port is a separate field)
 * rather than an http(s) URL. Shares the same SSRF checks and rebinding caveat.
 */
class PubliclyRoutableHost implements ValidationRule
{
    use ChecksHostRoutability;

    /**
     * @param  (callable(string): array<int, string>)|null  $resolver
     *   Optional host resolver; when provided, DNS is always checked.
     */
    public function __construct(protected $resolver = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The '.$attribute.' must include a valid host.');

            return;
        }

        if (($reason = $this->hostRoutabilityError(trim($value))) !== null) {
            $fail('The '.$attribute.' '.$reason);
        }
    }
}
