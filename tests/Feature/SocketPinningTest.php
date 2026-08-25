<?php

namespace Tests\Feature;

use App\Services\Amqp\AmqpTester;
use App\Services\Grpc\GrpcClient;
use App\Services\Mqtt\MqttTester;
use App\Services\Security\SsrfException;
use App\Services\Security\SsrfGuard;
use Tests\TestCase;

/**
 * The socket-based testers (gRPC, MQTT, AMQP) used to validate a host and then
 * let the client resolve the name again at connect — leaving the DNS-rebinding
 * window the HTTP testers close by pinning. These pin the validated address.
 */
class SocketPinningTest extends TestCase
{
    public function test_the_guard_returns_a_validated_address_to_connect_to(): void
    {
        $guard = new SsrfGuard(fn () => ['93.184.216.34']);

        $this->assertSame('93.184.216.34', $guard->validatedAddress('api.example.com'));
    }

    public function test_the_guard_rejects_a_host_resolving_anywhere_private(): void
    {
        $guard = new SsrfGuard(fn () => ['10.0.0.5']);

        $this->expectException(SsrfException::class);
        $guard->validatedAddress('sneaky.example.com');
    }

    public function test_every_address_must_be_public_not_just_the_first(): void
    {
        // A name answering with one public and one private address must be
        // rejected outright — picking the public one would be luck.
        $guard = new SsrfGuard(fn () => ['93.184.216.34', '192.168.0.10']);

        $this->expectException(SsrfException::class);
        $guard->validatedAddress('rebind.example.com');
    }

    public function test_pinning_does_not_apply_to_an_ip_literal_or_with_dns_off(): void
    {
        // Already an address: nothing left to re-resolve, so no pin is needed.
        $this->assertNull((new SsrfGuard(fn () => []))->validatedAddress('93.184.216.34'));

        config(['security.ssrf_resolve_dns' => false]);
        $this->assertNull((new SsrfGuard)->validatedAddress('api.example.com'));
    }

    public function test_a_blocked_or_private_literal_is_still_refused(): void
    {
        $guard = new SsrfGuard(fn () => []);

        foreach (['localhost', 'localhost.', '127.0.0.1', '127.0.0.1.', '169.254.169.254'] as $host) {
            try {
                $guard->validatedAddress($host);
                $this->fail("{$host} was not refused.");
            } catch (SsrfException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_mqtt_tester_refuses_a_host_that_resolves_privately(): void
    {
        $tester = new MqttTester(null, new SsrfGuard(fn () => ['127.0.0.1']));

        $this->expectException(SsrfException::class);
        $tester->run(['host' => 'broker.example.com', 'topic' => 'x', 'action' => 'publish']);
    }

    public function test_the_amqp_tester_refuses_a_host_that_resolves_privately(): void
    {
        // The guard runs in run(), before makeConnection, so an injected
        // connection factory cannot bypass it.
        $tester = new AmqpTester(
            fn () => $this->fail('A connection was attempted despite an unsafe host.'),
            new SsrfGuard(fn () => ['10.1.2.3'])
        );

        $this->expectException(SsrfException::class);
        $tester->run(['host' => 'rabbit.example.com', 'action' => 'publish']);
    }

    public function test_the_grpc_client_refuses_a_host_that_resolves_privately(): void
    {
        $client = new GrpcClient(
            transport: fn () => $this->fail('A request was sent despite an unsafe host.'),
            guard: new SsrfGuard(fn () => ['192.168.1.20'])
        );

        $this->expectException(SsrfException::class);
        $client->unary(['host' => 'grpc.example.com', 'service_method' => 'pkg.Svc/Method']);
    }

    public function test_the_grpc_client_still_calls_a_public_host(): void
    {
        $sent = null;
        $client = new GrpcClient(
            transport: function ($url) use (&$sent) {
                $sent = $url;

                return ['status' => 200, 'headers' => ['grpc-status' => '0'], 'body' => ''];
            },
            guard: new SsrfGuard(fn () => ['93.184.216.34'])
        );

        $client->unary(['host' => 'grpc.example.com', 'service_method' => 'pkg.Svc/Method']);

        // The URL keeps the hostname — pinning happens in the cURL options, so
        // TLS/SNI still sees the name.
        $this->assertSame('http://grpc.example.com:80/pkg.Svc/Method', $sent);
    }
}
