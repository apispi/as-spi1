<?php

namespace Tests\Feature;

use App\Models\RequestHistory;
use App\Models\User;
use App\Services\Grpc\GrpcClient;
use App\Services\Grpc\ProtobufCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrpcTestControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a GrpcClient whose transport returns a canned gRPC-framed response,
     * so the controller, framing, and codec are all exercised without a server.
     */
    private function fakeTransport(string $replyMessage = 'hello', int $grpcStatus = 0): void
    {
        $codec = new ProtobufCodec;
        $payload = $codec->encode([['field' => 1, 'type' => 'string', 'value' => $replyMessage]]);
        $frame = chr(0).pack('N', strlen($payload)).$payload;

        $this->app->instance(GrpcClient::class, new GrpcClient($codec, function () use ($frame, $grpcStatus) {
            return [
                'status' => 200,
                'headers' => ['grpc-status' => (string) $grpcStatus, 'content-type' => 'application/grpc'],
                'body' => $frame,
            ];
        }));
    }

    public function test_guests_cannot_reach_the_endpoint(): void
    {
        $this->postJson('/api/grpc/test', [
            'host' => 'grpc.example.com',
            'service_method' => 'helloworld.Greeter/SayHello',
        ])->assertStatus(401);
    }

    public function test_authenticated_user_can_make_a_unary_call(): void
    {
        $this->fakeTransport('hi there');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/grpc/test', [
            'host' => 'grpc.example.com',
            'port' => 443,
            'tls' => true,
            'service_method' => 'helloworld.Greeter/SayHello',
            'request' => [['field' => 1, 'type' => 'string', 'value' => 'world']],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('grpc_status', 0)
            ->assertJsonPath('grpc_status_name', 'OK')
            ->assertJsonPath('response.0.string', 'hi there');

        $this->assertDatabaseHas('request_histories', [
            'user_id' => $user->id,
            'protocol' => 'grpc',
            'method' => 'helloworld.Greeter/SayHello',
        ]);
    }

    public function test_surfaces_a_non_ok_grpc_status(): void
    {
        $this->fakeTransport('', 5);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/grpc/test', [
            'host' => 'grpc.example.com',
            'service_method' => 'helloworld.Greeter/SayHello',
        ])->assertStatus(200)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('grpc_status_name', 'NOT_FOUND');
    }

    public function test_rejects_a_private_host(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/grpc/test', [
            'host' => '127.0.0.1',
            'service_method' => 'helloworld.Greeter/SayHello',
        ])->assertStatus(422)->assertJsonValidationErrors('host');
    }

    public function test_rejects_a_malformed_service_method(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/grpc/test', [
            'host' => 'grpc.example.com',
            'service_method' => 'not-a-method',
        ])->assertStatus(422)->assertJsonValidationErrors('service_method');
    }

    public function test_returns_422_for_a_malformed_request_field(): void
    {
        // Bad field spec (unknown type) makes the codec throw; controller maps
        // that to a 422 rather than a transport error.
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/grpc/test', [
            'host' => 'grpc.example.com',
            'service_method' => 'helloworld.Greeter/SayHello',
            'request' => [['field' => 1, 'type' => 'widget', 'value' => 'x']],
        ])->assertStatus(422);

        $this->assertSame(0, RequestHistory::count());
    }
}
