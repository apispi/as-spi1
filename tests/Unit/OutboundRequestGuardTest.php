<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the app's most important security property by reading the source.
 *
 * Every outbound request to a user-supplied URL must go through the SSRF guard
 * — which validates the host is publicly routable and pins the resolved
 * address, so a target cannot re-resolve to an internal one between validation
 * and connection. That property holds today. It is also one line of new code
 * away from not holding, in a file nobody thinks to check, and the failure is
 * silent: the feature works perfectly while being an SSRF hole.
 *
 * So rather than trust review, this asserts the shape: a file that reaches the
 * network either goes through the guard, or is named below with a reason.
 */
class OutboundRequestGuardTest extends TestCase
{
    /**
     * Files allowed to call out without the guard, and why.
     *
     * Only a hardcoded destination belongs here. A configurable base URL does
     * not: config can be wrong, and "it is not user input" stops being true
     * the moment somebody makes it settable.
     */
    private const ALLOWED = [
        // Talks to one compile-time constant, ScxClient::ENDPOINT.
        'app/Services/Scx/ScxClient.php' => 'hardcoded provider endpoint',
        // Is the guard.
        'app/Services/Security/SsrfGuard.php' => 'the guard itself',
    ];

    /** What counts as going through the guard. */
    private const GUARD_MARKERS = ['SsrfGuard', 'pinnedOptions', 'RequestExecutor', 'PubliclyRoutable'];

    private function appFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    public function test_every_outbound_caller_is_guarded_or_explicitly_allowed(): void
    {
        $unguarded = [];
        $callers = 0;

        foreach ($this->appFiles() as $path => $source) {
            if (! str_contains($source, 'Http::')) {
                continue;
            }

            $callers++;

            if (array_key_exists($path, self::ALLOWED)) {
                continue;
            }

            $guarded = false;
            foreach (self::GUARD_MARKERS as $marker) {
                if (str_contains($source, $marker)) {
                    $guarded = true;
                    break;
                }
            }

            if (! $guarded) {
                $unguarded[] = $path;
            }
        }

        // A scan that found nothing to check would pass silently, which is
        // worse than not having the test.
        $this->assertGreaterThanOrEqual(
            5,
            $callers,
            'Found almost no outbound callers, so this test is not actually checking anything.'
        );

        $this->assertSame([], $unguarded, implode("\n", array_merge(
            ['These files reach the network without the SSRF guard:'],
            array_map(fn ($p) => '  - '.$p, $unguarded),
            [
                '',
                'Send the request through RequestExecutor, or validate with',
                'PubliclyRoutableUrl and pin via SsrfGuard::pinnedOptions().',
                'If the destination is a hardcoded constant, add the file to',
                self::class.'::ALLOWED with the reason.',
            ]
        )));
    }

    public function test_the_allowlist_has_no_stale_entries(): void
    {
        // An allowlist nobody prunes stops being a statement about the code and
        // becomes a place where exemptions accumulate.
        $files = $this->appFiles();

        foreach (self::ALLOWED as $path => $reason) {
            $this->assertArrayHasKey($path, $files, "Allowlisted file no longer exists: {$path}");
            $this->assertStringContainsString(
                'Http::',
                $files[$path],
                "{$path} is allowlisted for outbound calls ({$reason}) but makes none — remove the entry."
            );
        }
    }
}
