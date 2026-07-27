<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Mqtt\MqttTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MqttTestControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeTester(): void
    {
        $this->app->instance(MqttTester::class, new class extends MqttTester
        {
            public function run(array $opts): array
            {
                return [
                    'broker' => $opts['host'].':'.($opts['port'] ?? 1883),
                    'client_id' => 'apispi-test',
                    'action' => $opts['action'],
                    'topic' => $opts['topic'],
                    'qos' => $opts['qos'] ?? 0,
                    'published' => in_array($opts['action'], ['publish', 'publish_subscribe'], true),
                    'messages' => [['topic' => $opts['topic'], 'message' => 'pong']],
                    'message_count' => 1,
                ];
            }
        });
    }

    public function test_guests_cannot_reach_the_endpoint(): void
    {
        $this->postJson('/api/mqtt/test', [
            'host' => 'broker.example.com',
            'action' => 'publish',
            'topic' => 'test/topic',
        ])->assertStatus(401);
    }

    public function test_authenticated_user_can_publish(): void
    {
        $this->fakeTester();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/mqtt/test', [
            'host' => 'broker.example.com',
            'port' => 1883,
            'action' => 'publish',
            'topic' => 'sensors/temp',
            'message' => '21.5',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('published', true)
            ->assertJsonPath('broker', 'broker.example.com:1883');

        $this->assertDatabaseHas('request_histories', [
            'user_id' => $user->id,
            'protocol' => 'mqtt',
            'method' => 'publish sensors/temp',
        ]);
    }

    public function test_rejects_a_private_host(): void
    {
        $this->fakeTester();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/mqtt/test', [
            'host' => '10.0.0.1',
            'action' => 'publish',
            'topic' => 'test',
        ])->assertStatus(422)->assertJsonValidationErrors('host');
    }

    public function test_requires_a_topic_and_valid_action(): void
    {
        $this->fakeTester();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/mqtt/test', [
            'host' => 'broker.example.com',
            'action' => 'nonsense',
        ])->assertStatus(422)->assertJsonValidationErrors(['topic', 'action']);
    }
}
