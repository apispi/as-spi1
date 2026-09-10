<?php

namespace Database\Seeders;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\InspectionReport;
use App\Models\Monitor;
use App\Models\Organisation;
use App\Models\RequestHistory;
use App\Models\SavedRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a self-contained demo workspace so a fresh install has something to
 * look at: a demo user with environments, saved requests, a runnable
 * collection, request history, and a monitor with recent results.
 *
 * Everything is TAGGED as demo by ownership — the demo user carries
 * is_demo = true, and all content hangs off that user. `php artisan demo:clear`
 * removes the whole set by deleting the demo users (their data cascades), so
 * demo content can be wiped without touching real accounts.
 *
 * Idempotent: keyed on the demo email and item names, so re-running refreshes
 * rather than duplicating.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_EMAIL = 'demo@apispi.com';

    public function run(): void
    {
        $org = Organisation::firstOrCreate(
            ['slug' => 'demo-co'],
            ['name' => 'Demo Co', 'description' => 'Sample organisation for the demo workspace.']
        );

        $user = User::withTrashed()->updateOrCreate(
            ['email' => self::DEMO_EMAIL],
            [
                'name' => 'Demo User',
                'password' => Hash::make('demo-password-123'),
                'is_admin' => false,
                'is_demo' => true,
                'organisation_id' => $org->id,
                'email_verified_at' => now(),
                'deleted_at' => null,
            ]
        );

        $this->environments($user);
        [$listUsers, $createUser, $health] = $this->savedRequests($user);
        $collection = $this->collection($user, [$listUsers, $createUser, $health]);
        $this->history($user);
        $this->monitor($user, $collection);

        $this->command?->info('Demo workspace seeded for '.self::DEMO_EMAIL.'.');
    }

    private function environments(User $user): void
    {
        $envs = [
            ['name' => 'Demo Staging', 'is_default' => true, 'variables' => [
                ['key' => 'base_url', 'value' => 'https://staging.demo.example.com', 'secret' => false],
                ['key' => 'token', 'value' => 'stg-demo-token', 'secret' => true],
            ]],
            ['name' => 'Demo Production', 'is_default' => false, 'variables' => [
                ['key' => 'base_url', 'value' => 'https://api.demo.example.com', 'secret' => false],
                ['key' => 'token', 'value' => 'prod-demo-token', 'secret' => true],
            ]],
        ];

        foreach ($envs as $env) {
            Environment::updateOrCreate(
                ['user_id' => $user->id, 'name' => $env['name']],
                ['variables' => $env['variables'], 'is_default' => $env['is_default']],
            );
        }
    }

    /** @return array<int,SavedRequest> */
    private function savedRequests(User $user): array
    {
        $defs = [
            [
                'name' => 'List users', 'protocol' => 'rest', 'method' => 'GET',
                'url' => 'https://{{base_url}}/v1/users?page=1&limit=25',
                'headers' => ['Authorization' => 'Bearer {{token}}', 'Accept' => 'application/json'],
                'assertions' => [
                    ['path' => 'status', 'operator' => 'equals', 'expected' => '200', 'description' => 'OK'],
                    ['path' => 'data', 'operator' => 'is_type', 'expected' => 'array'],
                ],
            ],
            [
                'name' => 'Create user', 'protocol' => 'rest', 'method' => 'POST',
                'url' => 'https://{{base_url}}/v1/users',
                'headers' => ['Authorization' => 'Bearer {{token}}', 'Content-Type' => 'application/json'],
                'body' => '{"name": "Ada Lovelace", "email": "ada@example.com", "role": "admin"}',
                'assertions' => [
                    ['path' => 'status', 'operator' => 'equals', 'expected' => '201', 'description' => 'Created'],
                ],
            ],
            [
                'name' => 'Health check', 'protocol' => 'rest', 'method' => 'GET',
                'url' => 'https://{{base_url}}/healthz',
                'headers' => [],
                'assertions' => [
                    ['path' => 'status', 'operator' => 'equals', 'expected' => '200', 'description' => 'Healthy'],
                ],
            ],
        ];

        $saved = [];
        foreach ($defs as $def) {
            $saved[] = SavedRequest::updateOrCreate(
                ['user_id' => $user->id, 'name' => $def['name']],
                $def,
            );
        }

        return $saved;
    }

    /** @param  array<int,SavedRequest>  $requests */
    private function collection(User $user, array $requests): Collection
    {
        $collection = Collection::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'Demo smoke suite'],
            ['description' => 'Health, then list, then create — a quick smoke test of the demo API.', 'continue_on_failure' => false],
        );

        // Rebuild steps so re-seeding keeps the order clean.
        $collection->steps()->delete();
        foreach ([$requests[2], $requests[0], $requests[1]] as $position => $req) {
            $collection->steps()->create(['saved_request_id' => $req->id, 'position' => $position]);
        }

        return $collection;
    }

    private function history(User $user): void
    {
        // Only seed history once, so re-running does not pile up rows.
        if ($user->requestHistories()->exists()) {
            return;
        }

        $samples = [
            ['method' => 'GET', 'url' => 'https://api.demo.example.com/healthz', 'status' => 200, 'time_ms' => 42],
            ['method' => 'GET', 'url' => 'https://api.demo.example.com/v1/users?page=1', 'status' => 200, 'time_ms' => 118],
            ['method' => 'POST', 'url' => 'https://api.demo.example.com/v1/users', 'status' => 201, 'time_ms' => 205],
            ['method' => 'GET', 'url' => 'https://api.demo.example.com/v1/orders', 'status' => 500, 'time_ms' => 512],
        ];

        foreach ($samples as $i => $s) {
            RequestHistory::create([
                'user_id' => $user->id,
                'protocol' => 'rest',
                'method' => $s['method'],
                'url' => $s['url'],
                'status' => $s['status'],
                'time_ms' => $s['time_ms'],
            ] + ['created_at' => now()->subDays(3)->addHours($i)]);
        }
    }

    private function monitor(User $user, Collection $collection): void
    {
        $monitor = Monitor::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'Demo API uptime'],
            [
                'collection_id' => $collection->id,
                'type' => Monitor::TYPE_COLLECTION,
                'interval_minutes' => 60,
                'is_enabled' => true,
                'alerts_enabled' => false,
                'last_status' => Monitor::STATUS_PASSING,
                'last_run_at' => now()->subMinutes(12),
                'consecutive_failures' => 0,
            ],
        );

        if ($monitor->results()->exists()) {
            return;
        }

        // A handful of passing runs with a little latency variation, so the
        // history strip and latency sparkline have something to show.
        foreach (range(9, 0) as $i) {
            $report = InspectionReport::create([
                'user_id' => $user->id,
                'type' => 'collection_run',
                'summary' => 'Demo smoke suite — 3/3 passed',
                'data' => ['passed' => true, 'passed_count' => 3, 'total' => 3, 'time_ms' => 180 + $i * 7, 'steps' => []],
            ]);
            $monitor->results()->create([
                'inspection_report_id' => $report->id,
                'passed' => true,
                'time_ms' => 180 + $i * 7,
                'passed_count' => 3,
                'total' => 3,
                'summary' => 'All steps passed.',
                'created_at' => now()->subHours($i),
            ]);
        }
    }
}
