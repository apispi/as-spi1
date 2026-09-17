<?php

namespace App\Services\Variables;

/**
 * Substitutes {{variable}} placeholders in a request payload with the values
 * from the selected environment.
 *
 * Substitution is a single pass: a value that itself contains {{...}} is not
 * re-expanded, which keeps resolution terminating and predictable. Unknown
 * placeholders are left untouched and reported, so a typo shows up as an
 * obviously-unresolved request rather than a silently empty one.
 */
class VariableResolver
{
    /**
     * Placeholder names are restricted so that JSON bodies containing braces
     * (templating languages, JSON-in-JSON) are not mangled. A single leading
     * `$` is allowed so computed variables like `{{$uuid}}` match too.
     */
    private const PATTERN = '/\{\{\s*(\$?[A-Za-z0-9_.-]+)\s*\}\}/';

    private DynamicVariables $dynamic;

    public function __construct(?DynamicVariables $dynamic = null)
    {
        $this->dynamic = $dynamic ?? new DynamicVariables;
    }

    /**
     * Names encountered during the last resolve() that had no variable.
     *
     * @var string[]
     */
    private array $unresolved = [];

    /**
     * Names that were substituted during the last resolve().
     *
     * @var string[]
     */
    private array $used = [];

    /**
     * Walk a payload (arrays, strings) and substitute placeholders in every
     * string, including array keys — a header name can be templated too.
     */
    public function resolve(mixed $payload, array $variables): mixed
    {
        $this->unresolved = [];
        $this->used = [];

        return $this->walk($payload, $variables);
    }

    private function walk(mixed $value, array $variables): mixed
    {
        if (is_string($value)) {
            return $this->substitute($value, $variables);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $newKey = is_string($key) ? $this->substitute($key, $variables) : $key;
                $out[$newKey] = $this->walk($item, $variables);
            }

            return $out;
        }

        return $value;
    }

    private function substitute(string $subject, array $variables): string
    {
        if (! str_contains($subject, '{{')) {
            return $subject;
        }

        return preg_replace_callback(self::PATTERN, function ($matches) use ($variables) {
            $name = $matches[1];

            // An environment variable of the same name wins, so a computed
            // variable can be pinned to a fixed value when a test needs it.
            if (array_key_exists($name, $variables)) {
                $this->used[$name] = true;

                return $variables[$name];
            }

            // Computed variables ({{$uuid}}, {{$timestamp}}, …) produce a fresh
            // value each time they are used.
            if (str_starts_with($name, '$') && $this->dynamic->has($name)) {
                $this->used[$name] = true;

                return (string) $this->dynamic->resolve($name);
            }

            $this->unresolved[$name] = true;

            return $matches[0];
        }, $subject);
    }

    /**
     * @return string[]
     */
    public function unresolved(): array
    {
        return array_keys($this->unresolved);
    }

    /**
     * @return string[]
     */
    public function used(): array
    {
        return array_keys($this->used);
    }

    /**
     * True when the payload references at least one placeholder.
     */
    public static function containsPlaceholder(mixed $payload): bool
    {
        if (is_string($payload)) {
            return (bool) preg_match(self::PATTERN, $payload);
        }

        if (is_array($payload)) {
            foreach ($payload as $key => $item) {
                if (is_string($key) && preg_match(self::PATTERN, $key)) {
                    return true;
                }
                if (static::containsPlaceholder($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
