<?php

namespace Tests\Feature;

use App\Models\InspectionReport;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookTriggerTest extends TestCase
{
    use RefreshDatabase;

    private function collectionFor(User $user, string $url = 'https://api.example.com/verify')
    {
        $saved = $user->savedRequests()->create([
            'name' => 'Verify', 'protocol' => 'rest', 'method' => 'GET', 'url' => $url,
        ]);
        $collection = $user->collections()->create(['name' => 'Verification suite']);
        $collection->steps()->create(['saved_request_id' => $saved->id, 'position' => 0]);

        return $collection;
    }

    public function test_a_capture_fires_the_configured_collection_run(): void
    {
        // Sync queue in tests: the dispatched job runs immediately.
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $collection = $this->collectionFor($user);
        $endpoint = $user->webhookEndpoints()->create([
            'name' => 'Stripe callback', 'token' => WebhookEndpoint::generateToken(),
            'trigger_collection_id' => $collection->id,
        ]);

        $this->postJson("/hook/{$endpoint->token}", ['order_id' => 42])->assertOk();

        // The suite ran and a report was recorded, tagged with the endpoint.
        $report = InspectionReport::where('type', 'collection_run')->firstOrFail();
        $this->assertStringContainsString('webhook: Stripe callback', $report->summary);
        $this->assertSame('Stripe callback', $report->data['triggered_by']);
    }

    public function test_the_payload_is_exposed_as_webhook_variables_to_the_run(): void
    {
        // The suite hits a URL built from the captured order id.
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $collection = $this->collectionFor($user, 'https://api.example.com/orders/{{webhook_order_id}}');
        $endpoint = $user->webhookEndpoints()->create([
            'name' => 'Cb', 'token' => WebhookEndpoint::generateToken(),
            'trigger_collection_id' => $collection->id,
        ]);

        $this->postJson("/hook/{$endpoint->token}", ['order_id' => 777, 'nested' => ['ignored' => true]])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/orders/777'));
    }

    public function test_an_endpoint_without_a_trigger_runs_nothing(): void
    {
        $user = User::factory()->create();
        $endpoint = $user->webhookEndpoints()->create([
            'name' => 'Plain', 'token' => WebhookEndpoint::generateToken(),
        ]);

        $this->postJson("/hook/{$endpoint->token}", ['x' => 1])->assertOk();

        $this->assertSame(0, InspectionReport::count());
    }

    public function test_a_deleted_trigger_collection_simply_does_nothing(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $user = User::factory()->create();
        $collection = $this->collectionFor($user);
        $endpoint = $user->webhookEndpoints()->create([
            'name' => 'Cb', 'token' => WebhookEndpoint::generateToken(),
            'trigger_collection_id' => $collection->id,
        ]);

        // FK nulls out on delete, so the capture no longer triggers.
        $collection->delete();
        $endpoint->refresh();

        $this->postJson("/hook/{$endpoint->token}", ['x' => 1])->assertOk();
        $this->assertSame(0, InspectionReport::where('type', 'collection_run')->count());
    }

    public function test_the_trigger_can_be_configured_and_is_workspace_scoped(): void
    {
        $user = User::factory()->create();
        $collection = $this->collectionFor($user);

        $created = $this->actingAs($user)->postJson('/api/webhook-endpoints', [
            'name' => 'CI heartbeat', 'trigger_collection_id' => $collection->id,
        ])->assertStatus(201)->assertJsonPath('trigger_collection_id', $collection->id);

        // A collection from another workspace is rejected.
        $stranger = User::factory()->create();
        $theirs = $this->collectionFor($stranger);

        $this->actingAs($user)->putJson('/api/webhook-endpoints/'.$created->json('id'), [
            'name' => 'CI heartbeat', 'trigger_collection_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['trigger_collection_id']);
    }
}
