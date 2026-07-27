<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Amqp\AmqpTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmqpTestControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeTester(): void
    {
        $this->app->instance(AmqpTester::class, new class extends AmqpTester
        {
            public function run(array $opts): array
            {
                return [
                    'broker' => $opts['host'].':'.($opts['port'] ?? 5672),
                    'vhost' => $opts['vhost'] ?? '/',
                    'action' => $opts['action'],
                    'exchange' => $opts['exchange'] ?? '',
                    'routing_key' => $opts['routing_key'] ?? '',
                    'queue' => $opts['queue'] ?? null,
                    'published' => in_array($opts['action'], ['publish', 'publish_get'], true),
                    'messages' => [['body' => 'hello', 'routing_key' => 'rk', 'exchange' => '', 'redelivered' => false]],
                    'message_count' => 1,
                ];
            }
        });
    }

    public function test_guests_cannot_reach_the_endpoint(): void
    {
        $this->postJson('/api/amqp/test', [
            'host' => 'rabbit.example.com',
            'action' => 'publish',
            'routing_key' => 'jobs',
        ])->assertStatus(401);
    }

    public function test_authenticated_user_can_publish(): void
    {
        $this->fakeTester();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/amqp/test', [
            'host' => 'rabbit.example.com',
            'action' => 'publish',
            'exchange' => '',
            'routing_key' => 'jobs',
            'message' => '{"id":1}',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('published', true)
            ->assertJsonPath('broker', 'rabbit.example.com:5672');

        $this->assertDatabaseHas('request_histories', [
            'user_id' => $user->id,
            'protocol' => 'amqp',
            'method' => 'publish jobs',
        ]);
    }

    public function test_get_requires_a_queue(): void
    {
        $this->fakeTester();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/amqp/test', [
            'host' => 'rabbit.example.com',
            'action' => 'get',
        ])->assertStatus(422)->assertJsonValidationErrors('queue');
    }

    public function test_rejects_a_private_host(): void
    {
        $this->fakeTester();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/amqp/test', [
            'host' => '192.168.0.2',
            'action' => 'publish',
            'routing_key' => 'jobs',
        ])->assertStatus(422)->assertJsonValidationErrors('host');
    }
}
