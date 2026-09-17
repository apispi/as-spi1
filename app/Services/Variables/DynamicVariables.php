<?php

namespace App\Services\Variables;

use Illuminate\Support\Str;

/**
 * Computed variables that produce a fresh value each time they are used, so a
 * request can carry a unique id, the current time, or throwaway test data
 * without anyone maintaining an environment full of placeholder values.
 *
 * They are written `{{$name}}` — the leading `$` marks them as computed and
 * keeps them from ever colliding with an environment variable, whose names
 * cannot contain `$`. An environment variable is still allowed to shadow one
 * (resolution checks the environment first), so a test can pin `{{$timestamp}}`
 * to a fixed value when it needs determinism.
 */
class DynamicVariables
{
    /** First names drawn on for the random person helpers. */
    private const FIRST_NAMES = ['Ada', 'Grace', 'Alan', 'Linus', 'Rowan', 'Maya', 'Omar', 'Priya', 'Nina', 'Theo', 'Sofia', 'Jae'];

    private const LAST_NAMES = ['Lovelace', 'Hopper', 'Turing', 'Torvalds', 'Chen', 'Okoro', 'Haddad', 'Nakamoto', 'Rossi', 'Kaur', 'Silva', 'Novak'];

    private const WORDS = ['fox', 'ember', 'harbor', 'quartz', 'meadow', 'signal', 'copper', 'lantern', 'drift', 'willow', 'cobalt', 'ripple'];

    private const COLORS = ['red', 'green', 'blue', 'amber', 'violet', 'teal', 'crimson', 'olive', 'indigo', 'coral'];

    /** True when $token (including its leading `$`) names a known dynamic variable. */
    public function has(string $token): bool
    {
        return array_key_exists(ltrim($token, '$'), $this->generators());
    }

    /**
     * The value for $token, or null when it is not a known dynamic variable.
     * A fresh value is produced on every call — two `{{$uuid}}` in one request
     * are two different ids, matching what a caller expects.
     */
    public function resolve(string $token): ?string
    {
        $key = ltrim($token, '$');
        $generators = $this->generators();

        if (! isset($generators[$key])) {
            return null;
        }

        return (string) $generators[$key]();
    }

    /**
     * The catalogue, for documentation and an in-app reference. Each entry is
     * [token, one-line description]; a live example is generated on demand.
     *
     * @return array<int, array{token: string, description: string, example: string}>
     */
    public function catalogue(): array
    {
        $descriptions = [
            'guid' => 'A v4 UUID (alias of $uuid).',
            'uuid' => 'A v4 UUID.',
            'timestamp' => 'Current Unix time, in seconds.',
            'isoTimestamp' => 'Current time as an ISO 8601 string (UTC).',
            'randomInt' => 'A whole number from 0 to 1000.',
            'randomBoolean' => '"true" or "false".',
            'randomEmail' => 'A throwaway email address.',
            'randomFirstName' => 'A first name.',
            'randomLastName' => 'A last name.',
            'randomFullName' => 'A full name.',
            'randomUserName' => 'A username-style handle.',
            'randomWord' => 'A single lowercase word.',
            'randomWords' => 'A short run of words.',
            'randomColor' => 'A colour name.',
            'randomHexColor' => 'A hex colour, e.g. #3fb950.',
            'randomIP' => 'An IPv4 address.',
            'randomPhoneNumber' => 'A phone number.',
            'randomUrl' => 'A URL.',
        ];

        $out = [];
        foreach ($descriptions as $key => $description) {
            $out[] = [
                'token' => '$'.$key,
                'description' => $description,
                'example' => $this->resolve('$'.$key) ?? '',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, callable():(string|int)>
     */
    private function generators(): array
    {
        return [
            'guid' => fn () => (string) Str::uuid(),
            'uuid' => fn () => (string) Str::uuid(),
            'timestamp' => fn () => time(),
            'isoTimestamp' => fn () => gmdate('Y-m-d\TH:i:s\Z'),
            'randomInt' => fn () => random_int(0, 1000),
            'randomBoolean' => fn () => random_int(0, 1) ? 'true' : 'false',
            'randomEmail' => fn () => strtolower($this->pick(self::FIRST_NAMES).'.'.$this->pick(self::LAST_NAMES)).random_int(1, 999).'@example.com',
            'randomFirstName' => fn () => $this->pick(self::FIRST_NAMES),
            'randomLastName' => fn () => $this->pick(self::LAST_NAMES),
            'randomFullName' => fn () => $this->pick(self::FIRST_NAMES).' '.$this->pick(self::LAST_NAMES),
            'randomUserName' => fn () => strtolower($this->pick(self::FIRST_NAMES)).'_'.strtolower($this->pick(self::WORDS)).random_int(1, 99),
            'randomWord' => fn () => $this->pick(self::WORDS),
            'randomWords' => fn () => implode(' ', [$this->pick(self::WORDS), $this->pick(self::WORDS), $this->pick(self::WORDS)]),
            'randomColor' => fn () => $this->pick(self::COLORS),
            'randomHexColor' => fn () => sprintf('#%06x', random_int(0, 0xFFFFFF)),
            'randomIP' => fn () => implode('.', [random_int(1, 254), random_int(0, 255), random_int(0, 255), random_int(1, 254)]),
            'randomPhoneNumber' => fn () => sprintf('+1-%03d-%03d-%04d', random_int(200, 999), random_int(200, 999), random_int(0, 9999)),
            'randomUrl' => fn () => 'https://'.$this->pick(self::WORDS).'.example.com/'.$this->pick(self::WORDS),
        ];
    }

    private function pick(array $list): string
    {
        return $list[array_rand($list)];
    }
}
