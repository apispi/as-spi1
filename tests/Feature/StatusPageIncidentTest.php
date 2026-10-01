<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\StatusPage;
use App\Models\StatusPageIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPageIncidentTest extends TestCase
{
    use RefreshDatabase;

    private function page(User $user): StatusPage
    {
        return $user->statusPages()->create([
            'name' => 'Public status',
            'token' => StatusPage::generateToken(),
            'is_enabled' => true,
        ]);
    }

    private function open(User $user, StatusPage $page, array $overrides = [])
    {
        return $this->actingAs($user)->postJson("/api/status-pages/{$page->id}/incidents", array_merge([
            'title' => 'Elevated error rates',
            'body' => 'We are looking into reports of failed requests.',
        ], $overrides));
    }

    // --------------------------------------------------------------- opening

    public function test_opening_an_incident_starts_the_timeline(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);

        $response = $this->open($user, $page)
            ->assertCreated()
            ->assertJsonPath('status', 'investigating')
            ->assertJsonPath('resolved', false);

        // Every incident starts with a statement, not a bare title.
        $this->assertCount(1, $response->json('updates'));
        $this->assertSame('We are looking into reports of failed requests.', $response->json('updates.0.body'));
        $this->assertSame('investigating', $response->json('updates.0.status'));
    }

    public function test_an_incident_can_be_backdated(): void
    {
        // Incidents are usually written up once the firefighting stops; saying
        // it began then is more honest than saying it began when it got typed.
        $user = User::factory()->create();
        $page = $this->page($user);
        $began = now()->subHours(3);

        $this->open($user, $page, ['started_at' => $began->toIso8601String()])->assertCreated();

        $this->assertSame(
            $began->format('Y-m-d H:i'),
            StatusPageIncident::first()->started_at->format('Y-m-d H:i')
        );
    }

    public function test_a_title_and_a_body_are_both_required(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);

        $this->actingAs($user)->postJson("/api/status-pages/{$page->id}/incidents", ['title' => 'Only a title'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);

        $this->open($user, $page, ['status' => 'exploded'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_open_incidents_are_capped(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);

        for ($i = 0; $i < StatusPageIncident::MAX_OPEN; $i++) {
            $this->open($user, $page, ['title' => 'Incident '.$i])->assertCreated();
        }

        $this->open($user, $page, ['title' => 'One too many'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Resolve one first'));
    }

    // -------------------------------------------------------------- timeline

    public function test_updates_append_rather_than_overwrite(): void
    {
        // The history of what was believed when is most of what makes a status
        // page trustworthy after the fact.
        $user = User::factory()->create();
        $page = $this->page($user);
        $id = $this->open($user, $page)->json('id');

        $this->actingAs($user)->postJson("/api/status-pages/{$page->id}/incidents/{$id}/updates", [
            'status' => 'identified', 'body' => 'A bad deploy. Rolling back.',
        ])->assertOk()->assertJsonPath('status', 'identified');

        $response = $this->actingAs($user)->postJson("/api/status-pages/{$page->id}/incidents/{$id}/updates", [
            'status' => 'resolved', 'body' => 'Rollback complete, error rates normal.',
        ])->assertOk();

        $this->assertCount(3, $response->json('updates'));
        $this->assertSame('investigating', $response->json('updates.0.status'));
        $this->assertSame('identified', $response->json('updates.1.status'));
        $this->assertSame('resolved', $response->json('updates.2.status'));
        $this->assertTrue($response->json('resolved'));
        $this->assertNotNull($response->json('resolved_at'));
    }

    public function test_an_incident_can_be_reopened(): void
    {
        // Something called resolved too early should not need a second
        // incident to correct.
        $user = User::factory()->create();
        $page = $this->page($user);
        $id = $this->open($user, $page)->json('id');

        $this->actingAs($user)->postJson("/api/status-pages/{$page->id}/incidents/{$id}/updates", [
            'status' => 'resolved', 'body' => 'Looks fine now.',
        ])->assertOk();

        $this->actingAs($user)->postJson("/api/status-pages/{$page->id}/incidents/{$id}/updates", [
            'status' => 'investigating', 'body' => 'It is back. Reopening.',
        ])->assertOk()
            ->assertJsonPath('resolved', false)
            ->assertJsonPath('resolved_at', null);
    }

    public function test_the_title_can_be_corrected_without_touching_the_timeline(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        $id = $this->open($user, $page)->json('id');

        $this->actingAs($user)->putJson("/api/status-pages/{$page->id}/incidents/{$id}", [
            'title' => 'Elevated error rates on checkout',
        ])->assertOk()
            ->assertJsonPath('title', 'Elevated error rates on checkout')
            ->assertJsonCount(1, 'updates');
    }

    public function test_an_incident_can_be_removed(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        $id = $this->open($user, $page)->json('id');

        $this->actingAs($user)->deleteJson("/api/status-pages/{$page->id}/incidents/{$id}")->assertOk();

        $this->assertSame(0, StatusPageIncident::count());
    }

    // ----------------------------------------------------------- the public page

    public function test_the_public_page_publishes_open_incidents(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        $this->open($user, $page)->assertCreated();

        // No auth: this is what a visitor sees.
        $response = $this->getJson("/api/status/{$page->token}")
            ->assertOk()
            ->assertJsonPath('has_open_incident', true)
            ->assertJsonPath('incidents.0.title', 'Elevated error rates')
            ->assertJsonPath('incidents.0.status', 'investigating');

        $this->assertSame(
            'We are looking into reports of failed requests.',
            $response->json('incidents.0.updates.0.body')
        );
    }

    public function test_the_public_page_never_names_who_is_handling_it(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $page = $this->page($user);
        $this->open($user, $page)->assertCreated();

        $raw = $this->get("/api/status/{$page->token}")->assertOk()->getContent();

        $this->assertStringNotContainsString('Ada Lovelace', $raw);
        $this->assertStringNotContainsString('ada@example.com', $raw);
        // …while the owner's own view does say.
        $this->assertSame(
            'Ada Lovelace',
            $this->actingAs($user)->getJson("/api/status-pages/{$page->id}/incidents")->json('0.opened_by')
        );
    }

    public function test_a_recently_resolved_incident_stays_visible(): void
    {
        // A page that forgets an outage the moment it ends gives a visitor no
        // way to tell "fine now" from "fine all along".
        $user = User::factory()->create();
        $page = $this->page($user);
        $id = $this->open($user, $page)->json('id');

        $this->actingAs($user)->postJson("/api/status-pages/{$page->id}/incidents/{$id}/updates", [
            'status' => 'resolved', 'body' => 'Fixed.',
        ])->assertOk();

        $this->getJson("/api/status/{$page->token}")
            ->assertOk()
            ->assertJsonCount(1, 'incidents')
            ->assertJsonPath('incidents.0.resolved', true)
            ->assertJsonPath('has_open_incident', false);
    }

    public function test_an_old_resolved_incident_drops_off(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        $id = $this->open($user, $page)->json('id');

        StatusPageIncident::find($id)->update([
            'status' => 'resolved',
            'resolved_at' => now()->subDays(StatusPageIncident::RESOLVED_VISIBLE_DAYS + 1),
        ]);

        $this->getJson("/api/status/{$page->token}")->assertOk()->assertJsonCount(0, 'incidents');
    }

    public function test_an_incident_does_not_change_the_monitor_derived_status(): void
    {
        // The dot keeps meaning "what the checks say"; conflating the two would
        // make a green page with a posted incident read as a failing one.
        $user = User::factory()->create();
        $page = $this->page($user);
        $this->open($user, $page)->assertCreated();

        $this->getJson("/api/status/{$page->token}")
            ->assertOk()
            ->assertJsonPath('overall', 'unknown')
            ->assertJsonPath('has_open_incident', true);
    }

    public function test_a_disabled_page_publishes_nothing(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        $this->open($user, $page)->assertCreated();
        $page->update(['is_enabled' => false]);

        $this->getJson("/api/status/{$page->token}")->assertNotFound();
    }

    // --------------------------------------------------------------- access

    public function test_a_colleague_can_post_but_a_stranger_cannot(): void
    {
        $owner = User::factory()->create();
        $colleague = User::factory()->create();
        $organisation = Organisation::create([
            'name' => 'Acme', 'slug' => 'acme', 'owner_user_id' => $owner->id,
        ]);
        $owner->update(['organisation_id' => $organisation->id]);
        $colleague->update(['organisation_id' => $organisation->id]);

        $page = $this->page($owner);

        $this->open($colleague->fresh(), $page, ['title' => 'From a colleague'])->assertCreated();
        $this->open(User::factory()->create(), $page)->assertNotFound();
    }

    public function test_an_incident_on_another_workspaces_page_is_not_found(): void
    {
        $owner = User::factory()->create();
        $page = $this->page($owner);
        $id = $this->open($owner, $page)->json('id');

        $stranger = User::factory()->create();
        $strangerPage = $this->page($stranger);

        // Right incident id, wrong page: must not resolve across pages.
        $this->actingAs($stranger)
            ->postJson("/api/status-pages/{$strangerPage->id}/incidents/{$id}/updates", [
                'status' => 'resolved', 'body' => 'Not mine to close.',
            ])->assertNotFound();

        $this->assertFalse(StatusPageIncident::find($id)->isResolved());
    }

    public function test_the_owner_listing_counts_open_incidents(): void
    {
        // So an incident left open on a public page is visible without having
        // to open the page itself.
        $user = User::factory()->create();
        $page = $this->page($user);

        $this->actingAs($user)->getJson('/api/status-pages')->assertJsonPath('0.open_incidents', 0);

        $id = $this->open($user, $page)->json('id');
        $this->actingAs($user)->getJson('/api/status-pages')->assertJsonPath('0.open_incidents', 1);

        $this->actingAs($user)->postJson("/api/status-pages/{$page->id}/incidents/{$id}/updates", [
            'status' => 'resolved', 'body' => 'Done.',
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/status-pages')->assertJsonPath('0.open_incidents', 0);
    }

    public function test_the_public_page_says_what_each_monitor_watches(): void
    {
        // Everything that was not MCP drift used to be labelled "checks", so a
        // schema monitor said nothing about what it actually watches.
        $user = User::factory()->create();
        $page = $this->page($user);

        $kinds = [
            \App\Models\Monitor::TYPE_GRAPHQL_DRIFT => 'graphql_schema',
            \App\Models\Monitor::TYPE_OPENAPI_DRIFT => 'openapi_schema',
            \App\Models\Monitor::TYPE_MCP_DRIFT => 'mcp_contract',
        ];

        foreach (array_keys($kinds) as $i => $type) {
            $monitor = $user->monitors()->create([
                'name' => 'Watcher '.$i,
                'type' => $type,
                'target_url' => 'https://api.example.com/'.$i,
                'interval_minutes' => 60,
            ]);
            $page->monitors()->attach($monitor->id, ['position' => $i]);
        }

        $published = collect($this->getJson("/api/status/{$page->token}")->assertOk()->json('monitors'));

        $this->assertEqualsCanonicalizing(
            array_values($kinds),
            $published->pluck('kind')->all()
        );
    }

    public function test_the_incident_routes_require_authentication(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);

        $this->getJson("/api/status-pages/{$page->id}/incidents")->assertUnauthorized();
        $this->postJson("/api/status-pages/{$page->id}/incidents", [
            'title' => 'x', 'body' => 'y',
        ])->assertUnauthorized();
    }
}
