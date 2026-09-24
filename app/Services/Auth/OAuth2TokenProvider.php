<?php

namespace App\Services\Auth;

use App\Rules\PubliclyRoutableUrl;
use App\Services\Security\SsrfException;
use App\Services\Security\SsrfGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Fetches an access token with the OAuth 2.0 client-credentials grant.
 *
 * This is the grant machine-to-machine testing actually uses, and doing it by
 * hand means pasting a token that expires an hour later. Tokens are cached for
 * a little less than their stated lifetime, so a collection of twenty steps
 * costs one token request, not twenty.
 *
 * The cache key is a hash of the whole config, credentials included: two
 * requests naming the same client against the same token endpoint are the same
 * OAuth client and may share a token, while any difference — a different
 * secret, scope, or audience — is a different key. No user id is needed for
 * that to hold, and the hash means the key itself discloses nothing.
 */
class OAuth2TokenProvider
{
    /** Where the client credentials are placed on the token request. */
    public const PLACEMENTS = ['basic', 'body'];

    /** Shaved off the stated lifetime so a token cannot expire mid-run. */
    private const EXPIRY_MARGIN_SECONDS = 30;

    /** Cap on how long a token is reused, however long the server claims. */
    private const MAX_TTL_SECONDS = 3600;

    /** Used when the response omits expires_in. */
    private const DEFAULT_TTL_SECONDS = 300;

    /**
     * @return array{token: ?string, error: ?string}
     */
    public function token(array $config): array
    {
        $tokenUrl = trim((string) ($config['token_url'] ?? ''));
        $clientId = (string) ($config['client_id'] ?? '');
        $clientSecret = (string) ($config['client_secret'] ?? '');
        $scope = trim((string) ($config['scope'] ?? ''));
        $audience = trim((string) ($config['audience'] ?? ''));
        $placement = ($config['credentials_in'] ?? 'basic') === 'body' ? 'body' : 'basic';

        if ($tokenUrl === '' || $clientId === '') {
            return ['token' => null, 'error' => 'OAuth 2.0 needs a token URL and a client ID.'];
        }

        // The token endpoint is a URL the user supplies, so it gets exactly the
        // same SSRF treatment as the request target itself.
        $validator = Validator::make(
            ['url' => $tokenUrl],
            ['url' => ['required', 'url', new PubliclyRoutableUrl]]
        );

        if ($validator->fails()) {
            return ['token' => null, 'error' => 'Token URL rejected: '.$validator->errors()->first('url')];
        }

        $key = 'oauth2:'.hash('sha256', implode("\0", [
            $tokenUrl, $clientId, $clientSecret, $scope, $audience, $placement,
        ]));

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return ['token' => $cached, 'error' => null];
        }

        $result = $this->request($tokenUrl, $clientId, $clientSecret, $scope, $audience, $placement);

        if ($result['token'] !== null && $result['ttl'] > 0) {
            Cache::put($key, $result['token'], $result['ttl']);
        }

        return ['token' => $result['token'], 'error' => $result['error']];
    }

    /**
     * @return array{token: ?string, ttl: int, error: ?string}
     */
    private function request(string $url, string $id, string $secret, string $scope, string $audience, string $placement): array
    {
        $form = array_filter([
            'grant_type' => 'client_credentials',
            'scope' => $scope !== '' ? $scope : null,
            'audience' => $audience !== '' ? $audience : null,
        ]);

        if ($placement === 'body') {
            $form['client_id'] = $id;
            $form['client_secret'] = $secret;
        }

        try {
            // Pin the validated address so the token endpoint cannot re-resolve
            // inward between validation and connection.
            $pinned = (new SsrfGuard)->pinnedOptions($url);
        } catch (SsrfException $e) {
            return ['token' => null, 'ttl' => 0, 'error' => 'Token URL rejected: '.$e->getMessage()];
        }

        try {
            // Do not follow redirects: one would carry the client credentials
            // to a host the guard never validated.
            $pending = Http::asForm()
                ->withOptions(['allow_redirects' => false] + $pinned)
                ->timeout(15);

            if ($placement === 'basic') {
                $pending = $pending->withBasicAuth($id, $secret);
            }

            $response = $pending->post($url, $form);
        } catch (Throwable $e) {
            return ['token' => null, 'ttl' => 0, 'error' => 'Token request failed: '.$e->getMessage()];
        }

        if ($response->status() < 200 || $response->status() >= 300) {
            return [
                'token' => null,
                'ttl' => 0,
                // OAuth error bodies are small and diagnostic ("invalid_client"),
                // so surfacing one saves a round of guessing.
                'error' => 'Token endpoint returned '.$response->status().$this->detail($response->body()),
            ];
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_string($payload['access_token'] ?? null) || $payload['access_token'] === '') {
            return ['token' => null, 'ttl' => 0, 'error' => 'Token endpoint returned no access_token.'];
        }

        return [
            'token' => $payload['access_token'],
            'ttl' => $this->ttl($payload['expires_in'] ?? null),
            'error' => null,
        ];
    }

    /** How long to reuse the token: its own lifetime, less a margin, capped. */
    private function ttl(mixed $expiresIn): int
    {
        if (! is_numeric($expiresIn)) {
            return self::DEFAULT_TTL_SECONDS;
        }

        $ttl = (int) $expiresIn - self::EXPIRY_MARGIN_SECONDS;

        // A token already inside the margin is used once and not cached.
        return max(0, min($ttl, self::MAX_TTL_SECONDS));
    }

    /** A short, single-line excerpt of an error body. */
    private function detail(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        if ($body === '') {
            return '.';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) && is_string($decoded['error'] ?? null)) {
            $body = $decoded['error'].(is_string($decoded['error_description'] ?? null) ? ': '.$decoded['error_description'] : '');
        }

        return ' — '.mb_substr($body, 0, 200);
    }
}
