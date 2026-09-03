<?php

namespace App\Http\Controllers;

use App\Services\Admin\LogReader;
use Illuminate\Http\Request;

/**
 * Admin-only viewer over the application log files. Read-only, tail-bounded,
 * and path-safe: only files matching the Laravel log naming pattern inside the
 * log directory can be opened.
 */
class AdminLogController extends Controller
{
    public function __construct(private readonly LogReader $reader)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'file' => 'nullable|string|max:255',
            'level' => 'nullable|in:'.implode(',', LogReader::LEVELS),
            'q' => 'nullable|string|max:200',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $dir = $this->logDir();
        $files = $this->availableFiles($dir);

        $selected = $this->resolveFile($dir, $files, $validated['file'] ?? null);

        if ($selected === null) {
            return response()->json([
                'files' => $files,
                'file' => null,
                'entries' => [],
                'counts' => [],
                'generated_at' => now(),
                'message' => 'No log files found.',
            ]);
        }

        $parsed = $this->reader->read($dir.DIRECTORY_SEPARATOR.$selected['name']);
        $entries = $this->reader->filter(
            $parsed['entries'],
            $validated['level'] ?? null,
            $validated['q'] ?? null,
            $validated['limit'] ?? 200,
        );

        return response()->json([
            'files' => $files,
            'file' => $selected['name'],
            'entries' => $entries,
            'counts' => $parsed['counts'],
            'returned' => count($entries),
            'scanned' => count($parsed['entries']),
            'generated_at' => now(),
        ]);
    }

    private function logDir(): string
    {
        // Derive from the configured single-channel path so it tracks config.
        $path = config('logging.channels.single.path', storage_path('logs/laravel.log'));

        return dirname((string) $path);
    }

    /**
     * Laravel log files only (single `laravel.log` and daily
     * `laravel-YYYY-MM-DD.log`), newest first, with sizes.
     *
     * @return array<int,array{name:string,size:int,modified:string}>
     */
    private function availableFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (glob($dir.DIRECTORY_SEPARATOR.'laravel*.log') ?: [] as $full) {
            $name = basename($full);
            if (! $this->isLogName($name)) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'size' => (int) filesize($full),
                'modified' => date('c', (int) filemtime($full)),
            ];
        }

        usort($files, fn ($a, $b) => strcmp($b['modified'], $a['modified']));

        return $files;
    }

    /**
     * Pick the requested file if it is a valid, existing log; otherwise the
     * newest. Guards against path traversal — only a bare basename that matches
     * the log pattern and appears in the directory listing is accepted.
     *
     * @param  array<int,array{name:string,size:int,modified:string}>  $files
     * @return array{name:string,size:int,modified:string}|null
     */
    private function resolveFile(string $dir, array $files, ?string $requested): ?array
    {
        if ($files === []) {
            return null;
        }

        if ($requested !== null && $requested !== '') {
            $base = basename($requested); // strip any directory component
            foreach ($files as $f) {
                if ($f['name'] === $base) {
                    return $f;
                }
            }
        }

        return $files[0];
    }

    private function isLogName(string $name): bool
    {
        return (bool) preg_match('/^laravel(-\d{4}-\d{2}-\d{2})?\.log$/', $name);
    }
}
