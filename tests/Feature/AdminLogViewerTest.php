<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\LogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private string $tempLog;

    protected function tearDown(): void
    {
        if (isset($this->tempLog) && is_file($this->tempLog)) {
            @unlink($this->tempLog);
        }
        parent::tearDown();
    }

    /** Write a uniquely-named log file into the real log dir so the endpoint can read it. */
    private function writeLog(string $contents): string
    {
        $dir = dirname((string) config('logging.channels.single.path', storage_path('logs/laravel.log')));
        // Daily naming so it matches the allowed pattern; a far-future date so it
        // is unlikely to collide or be treated as "newest" unpredictably.
        $this->tempLog = $dir.'/laravel-2099-01-01.log';
        file_put_contents($this->tempLog, $contents);

        return basename($this->tempLog);
    }

    private const SAMPLE = <<<'LOG'
[2026-09-04 01:00:00] production.INFO: User signed in {"id":1}
[2026-09-04 01:01:00] production.WARNING: Slow query detected
[2026-09-04 01:02:00] production.ERROR: Something exploded
#0 /app/foo.php(12): boom()
#1 {main}
[2026-09-04 01:03:00] production.DEBUG: cache hit
LOG;

    public function test_reader_parses_and_groups_multiline_entries(): void
    {
        $reader = new LogReader;
        $path = tempnam(sys_get_temp_dir(), 'log');
        file_put_contents($path, self::SAMPLE);

        $parsed = $reader->read($path);
        @unlink($path);

        // Newest first.
        $this->assertSame('debug', $parsed['entries'][0]['level']);
        $this->assertSame('error', $parsed['entries'][1]['level']);
        // The stack-trace lines fold into the error entry's detail.
        $this->assertStringContainsString('#0 /app/foo.php', $parsed['entries'][1]['detail']);
        $this->assertSame(4, count($parsed['entries']));
        $this->assertSame(1, $parsed['counts']['error']);
    }

    public function test_reader_filters_by_level_severity(): void
    {
        $reader = new LogReader;
        $path = tempnam(sys_get_temp_dir(), 'log');
        file_put_contents($path, self::SAMPLE);
        $parsed = $reader->read($path);
        @unlink($path);

        // "warning" keeps warning + error (more severe), drops info + debug.
        $levels = collect($reader->filter($parsed['entries'], 'warning', null, 100))->pluck('level')->all();
        $this->assertContains('error', $levels);
        $this->assertContains('warning', $levels);
        $this->assertNotContains('info', $levels);
        $this->assertNotContains('debug', $levels);
    }

    public function test_reader_filters_by_query(): void
    {
        $reader = new LogReader;
        $path = tempnam(sys_get_temp_dir(), 'log');
        file_put_contents($path, self::SAMPLE);
        $parsed = $reader->read($path);
        @unlink($path);

        $hits = $reader->filter($parsed['entries'], null, 'exploded', 100);
        $this->assertCount(1, $hits);
        $this->assertSame('error', $hits[0]['level']);
    }

    public function test_endpoint_requires_admin(): void
    {
        // A signed-in non-admin is forbidden.
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->getJson('/api/admin/logs')->assertStatus(403);

        // A guest is denied too (admin routes reject unauthenticated access).
        $this->getJson('/api/admin/logs')->assertStatus(403);
    }

    public function test_admin_can_read_a_log_file(): void
    {
        $file = $this->writeLog(self::SAMPLE);
        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->getJson('/api/admin/logs?file='.$file)
            ->assertOk()
            ->assertJsonPath('file', $file);

        $levels = collect($res->json('entries'))->pluck('level')->all();
        $this->assertContains('error', $levels);
        $this->assertTrue(collect($res->json('files'))->contains('name', $file));
    }

    public function test_admin_can_filter_endpoint_by_level(): void
    {
        $file = $this->writeLog(self::SAMPLE);
        $admin = User::factory()->create(['is_admin' => true]);

        $res = $this->actingAs($admin)->getJson('/api/admin/logs?file='.$file.'&level=error');
        $levels = collect($res->json('entries'))->pluck('level')->unique()->all();

        $this->assertEquals(['error'], array_values($levels));
    }

    public function test_path_traversal_is_rejected(): void
    {
        $this->writeLog(self::SAMPLE);
        $admin = User::factory()->create(['is_admin' => true]);

        // A traversal attempt falls back to the newest real log, never /etc/passwd.
        $res = $this->actingAs($admin)->getJson('/api/admin/logs?file=../../../../etc/passwd')->assertOk();
        $this->assertNotSame('passwd', $res->json('file'));
        $this->assertStringStartsWith('laravel', (string) $res->json('file'));
    }
}
