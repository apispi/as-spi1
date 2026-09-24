<?php

namespace App\Services\Auth;

/**
 * Turns a saved request's auth config into the header (or query parameter) the
 * wire actually carries, so nobody has to hand-assemble "Basic " + base64, or
 * remember which header a particular API wants its key in.
 *
 * Values are ordinarily `{{variables}}`: by the time a request reaches here the
 * environment has already been substituted in, which means the credential lives
 * in a secret environment variable — masked in history and reports — instead of
 * in the saved-request row. Nothing here stores or logs a credential; it only
 * places one on the outgoing request.
 */
class RequestAuthenticator
{
    public const SCHEMES = ['none', 'bearer', 'basic', 'api_key', 'oauth2_client_credentials'];

    /** Where an API key may be placed. */
    public const LOCATIONS = ['header', 'query'];

    public function __construct(private readonly ?OAuth2TokenProvider $oauth = null)
    {
    }

    /**
     * Validation rules for an auth config, keyed under $prefix.
     *
     * Every field is listed explicitly and deliberately: `validated()` returns
     * only the keys that carry a rule, so an unlisted field would be silently
     * dropped between the request and the wire — the credential would vanish
     * and the request would simply go out unauthenticated.
     */
    public static function rules(string $prefix = 'auth'): array
    {
        return [
            $prefix => 'nullable|array',
            $prefix.'.scheme' => 'nullable|string|in:'.implode(',', self::SCHEMES),
            $prefix.'.token' => 'nullable|string|max:4096',
            $prefix.'.username' => 'nullable|string|max:255',
            $prefix.'.password' => 'nullable|string|max:1024',
            $prefix.'.key' => 'nullable|string|max:128',
            $prefix.'.value' => 'nullable|string|max:4096',
            $prefix.'.in' => 'nullable|string|in:'.implode(',', self::LOCATIONS),
            $prefix.'.token_url' => 'nullable|string|max:2048',
            $prefix.'.client_id' => 'nullable|string|max:255',
            $prefix.'.client_secret' => 'nullable|string|max:1024',
            $prefix.'.scope' => 'nullable|string|max:512',
            $prefix.'.audience' => 'nullable|string|max:512',
            $prefix.'.credentials_in' => 'nullable|string|in:'.implode(',', OAuth2TokenProvider::PLACEMENTS),
        ];
    }

    /**
     * Apply the config to a request's headers and URL.
     *
     * The auth config wins over a hand-typed header of the same name: it is the
     * dedicated setting, and the one the UI shows as active — silently losing
     * to a stale header would be the harder bug to find.
     *
     * `error` is set when the scheme could not be satisfied — currently only
     * OAuth 2.0, which has to go and fetch a token. Callers must surface it and
     * abandon the request: sending it anyway would strip the credential and
     * report back whatever the target says to an anonymous caller, which is a
     * far more confusing failure than "the token request was refused".
     *
     * @param  mixed  $auth  the (already variable-resolved) auth config, or null
     * @return array{headers: array, url: string, error: ?string}
     */
    public function apply(mixed $auth, array $headers, string $url): array
    {
        $scheme = is_array($auth) ? (string) ($auth['scheme'] ?? 'none') : 'none';

        if (! in_array($scheme, self::SCHEMES, true) || $scheme === 'none') {
            return $this->result($headers, $url);
        }

        if ($scheme === 'oauth2_client_credentials') {
            $result = ($this->oauth ?? new OAuth2TokenProvider)->token($auth);

            if ($result['token'] === null) {
                return $this->result($headers, $url, $result['error'] ?? 'Could not obtain an access token.');
            }

            return $this->result($this->setHeader($headers, 'Authorization', 'Bearer '.$result['token']), $url);
        }

        return match ($scheme) {
            'bearer' => $this->result($this->bearer($auth, $headers), $url),
            'basic' => $this->result($this->basic($auth, $headers), $url),
            default => $this->apiKey($auth, $headers, $url),
        };
    }

    /**
     * @return array{headers: array, url: string, error: ?string}
     */
    private function result(array $headers, string $url, ?string $error = null): array
    {
        return ['headers' => $headers, 'url' => $url, 'error' => $error];
    }

    private function bearer(array $auth, array $headers): array
    {
        $token = trim((string) ($auth['token'] ?? ''));

        if ($token === '') {
            return $headers;
        }

        // A token pasted with its scheme already on the front is a common slip;
        // sending "Bearer Bearer x" helps nobody.
        if (preg_match('/^bearer\s+/i', $token)) {
            $token = preg_replace('/^bearer\s+/i', '', $token);
        }

        return $this->setHeader($headers, 'Authorization', 'Bearer '.$token);
    }

    private function basic(array $auth, array $headers): array
    {
        $username = (string) ($auth['username'] ?? '');
        $password = (string) ($auth['password'] ?? '');

        if ($username === '' && $password === '') {
            return $headers;
        }

        return $this->setHeader($headers, 'Authorization', 'Basic '.base64_encode($username.':'.$password));
    }

    /**
     * @return array{headers: array, url: string, error: ?string}
     */
    private function apiKey(array $auth, array $headers, string $url): array
    {
        $name = trim((string) ($auth['key'] ?? ''));
        $value = (string) ($auth['value'] ?? '');
        $in = ($auth['in'] ?? 'header') === 'query' ? 'query' : 'header';

        if ($name === '') {
            return $this->result($headers, $url);
        }

        if ($in === 'header') {
            return $this->result($this->setHeader($headers, $name, $value), $url);
        }

        return $this->result($headers, $this->withQueryParameter($url, $name, $value));
    }

    /**
     * Set a header, replacing any existing one whose name matches case-
     * insensitively — HTTP header names are not case-sensitive, and leaving
     * both "authorization" and "Authorization" on the request is undefined.
     */
    private function setHeader(array $headers, string $name, string $value): array
    {
        $needle = strtolower($name);

        foreach (array_keys($headers) as $existing) {
            if (strtolower((string) $existing) === $needle) {
                unset($headers[$existing]);
            }
        }

        $headers[$name] = $value;

        return $headers;
    }

    /**
     * Append (or replace) a query parameter, preserving the rest of the URL.
     * A URL that will not parse is returned untouched — the SSRF guard and URL
     * validation downstream will reject it with a better message than anything
     * this could invent.
     */
    private function withQueryParameter(string $url, string $name, string $value): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        $query[$name] = $value;

        $rebuilt = ($parts['scheme'] ?? 'https').'://';
        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@';
        }
        $rebuilt .= $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= '?'.http_build_query($query);
        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }
}
