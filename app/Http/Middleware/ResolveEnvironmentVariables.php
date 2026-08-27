<?php

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Services\Variables\SecretMasker;
use App\Services\Variables\VariableResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expands {{variable}} placeholders in tester payloads before the controller
 * validates them.
 *
 * Running ahead of validation is deliberate: every URL rule (including the
 * SSRF guard in PubliclyRoutableUrl) then sees the real target, so a variable
 * cannot be used to smuggle an unvalidated host past those checks.
 *
 * Which environment applies:
 *   1. an explicit `environment_id` (or `environment` name) in the payload;
 *   2. otherwise the user's default environment, but only when the payload
 *      actually contains a placeholder — so requests without variables behave
 *      identically to before.
 */
class ResolveEnvironmentVariables
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $payload = $request->all();

        $selector = $payload['environment_id'] ?? $payload['environment'] ?? null;

        // The selector is ours, not the tester's — never forward it.
        unset($payload['environment_id'], $payload['environment']);
        $request->replace($payload);

        if (! $user) {
            return $next($request);
        }

        $environment = $this->pick($user, $selector, $payload);

        if (! $environment) {
            if ($selector !== null) {
                return response()->json([
                    'error' => 'Unknown environment: '.$selector,
                ], 422);
            }

            return $next($request);
        }

        $resolver = new VariableResolver;
        $request->replace($resolver->resolve($payload, $environment->map()));

        app(SecretMasker::class)->remember($environment->secretValues());

        $response = $next($request);

        return $this->annotate($response, $environment, $resolver);
    }

    /**
     * Resolve the selector to an environment in the user's workspace.
     */
    private function pick(\App\Models\User $user, mixed $selector, array $payload): ?Environment
    {
        $query = Environment::inWorkspaceOf($user);

        if ($selector !== null && $selector !== '') {
            return is_numeric($selector)
                ? $query->find((int) $selector)
                : $query->where('name', (string) $selector)->first();
        }

        if (! VariableResolver::containsPlaceholder($payload)) {
            return null;
        }

        return $query->where('is_default', true)->first();
    }

    /**
     * Mask secrets in the echoed request and tell the client which environment
     * ran and which placeholders had no value.
     */
    private function annotate(Response $response, Environment $environment, VariableResolver $resolver): Response
    {
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data)) {
            return $response;
        }

        $masker = app(SecretMasker::class);

        foreach (['request_payload', 'request_headers', 'body'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $masker->mask($data[$key]);
            }
        }

        $data['environment'] = [
            'id' => $environment->id,
            'name' => $environment->name,
            'resolved' => $resolver->used(),
            'unresolved' => $resolver->unresolved(),
        ];

        $response->setData($data);

        return $response;
    }
}
