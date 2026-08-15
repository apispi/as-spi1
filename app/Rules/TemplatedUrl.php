<?php

namespace App\Rules;

use App\Services\Variables\VariableResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * Accepts either a real URL or one containing {{variable}} placeholders.
 *
 * Saved requests store the template — "https://{{host}}/users" is the whole
 * point of environments — so they cannot be validated as URLs at save time.
 * The resolved URL is validated, and SSRF-checked, when the request is
 * actually sent (see PubliclyRoutableUrl on the tester endpoints).
 *
 * Broker protocols (gRPC, MQTT, AMQP) put a bare host in this field rather
 * than a URL, so a host-shaped value is accepted too.
 */
class TemplatedUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute field is required.');

            return;
        }

        if (VariableResolver::containsPlaceholder($value)) {
            return;
        }

        if (! Validator::make([$attribute => $value], [$attribute => 'url'])->fails()) {
            return;
        }

        // host or host:port, for the broker protocols.
        if (preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $value)) {
            return;
        }

        $fail('The :attribute must be a valid URL or contain a {{variable}}.');
    }
}
