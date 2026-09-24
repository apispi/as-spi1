<?php

namespace Tests\Feature;

use App\Mail\WorkspaceInvitationMail;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkspaceInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** Invite someone and recover the raw token from the returned link. */
    private function inviteAndToken(User $inviter, string $email): string
    {
        $response = $this->actingAs($inviter)
            ->postJson('/api/workspace/invitations', ['email' => $email])
            ->assertCreated();

        return basename(parse_url($response->json('url'), PHP_URL_PATH));
    }

    private function organisationWith(User ...$members): Organisation
    {
        $organisation = Organisation::create([
            'name' => 'Acme', 'slug' => 'acme', 'owner_user_id' => $members[0]->id,
        ]);

        foreach ($members as $member) {
            $member->update(['organisation_id' => $organisation->id]);
        }

        return $organisation;
    }

    // ------------------------------------------------------------- inviting

    public function test_inviting_from_a_solo_account_creates_the_workspace(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        $this->assertNull($user->organisation_id);

        $this->actingAs($user)->postJson('/api/workspace/invitations', ['email' => 'grace@example.com'])
            ->assertCreated()
            ->assertJsonPath('email', 'grace@example.com');

        $user->refresh();
        $this->assertNotNull($user->organisation_id);
        $this->assertSame("Ada Lovelace's workspace", $user->organisation->name);
        // …and the person who created it owns it.
        $this->assertTrue($user->organisation->isOwnedBy($user));
    }

    public function test_the_invitation_email_carries_the_link(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/workspace/invitations', ['email' => 'grace@example.com'])
            ->assertCreated();

        Mail::assertSent(WorkspaceInvitationMail::class, function ($mail) use ($response) {
            return $mail->hasTo('grace@example.com') && $mail->url === $response->json('url');
        });
    }

    public function test_the_token_is_stored_only_as_a_hash(): void
    {
        $user = User::factory()->create();
        $token = $this->inviteAndToken($user, 'grace@example.com');

        $this->assertDatabaseMissing('workspace_invitations', ['token_hash' => $token]);
        $this->assertNotNull(WorkspaceInvitation::findByToken($token));
    }

    public function test_a_mail_failure_does_not_lose_the_invitation(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/workspace/invitations', ['email' => 'grace@example.com'])
            ->assertCreated()
            // The link is still returned so it can be sent by hand.
            ->assertJsonStructure(['url']);

        $this->assertDatabaseCount('workspace_invitations', 1);
    }

    public function test_you_cannot_invite_yourself_or_an_existing_member(): void
    {
        $owner = User::factory()->create(['email' => 'ada@example.com']);
        $member = User::factory()->create(['email' => 'grace@example.com']);
        $this->organisationWith($owner, $member);

        $this->actingAs($owner)->postJson('/api/workspace/invitations', ['email' => 'ADA@example.com'])
            ->assertStatus(422)->assertJsonPath('message', 'You are already in this workspace.');

        $this->actingAs($owner)->postJson('/api/workspace/invitations', ['email' => 'grace@example.com'])
            ->assertStatus(422)->assertJsonPath('message', 'That person is already in this workspace.');
    }

    public function test_a_duplicate_pending_invitation_is_refused(): void
    {
        $user = User::factory()->create();
        $this->inviteAndToken($user, 'grace@example.com');

        $this->actingAs($user)->postJson('/api/workspace/invitations', ['email' => 'grace@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already a pending invitation'));
    }

    public function test_inviting_is_audited(): void
    {
        $user = User::factory()->create();
        $this->inviteAndToken($user, 'grace@example.com');

        $this->assertDatabaseHas('audit_events', [
            'user_id' => $user->id,
            'action' => 'workspace.invited',
        ]);
    }

    // ------------------------------------------------------------- previewing

    public function test_the_public_preview_says_what_is_being_joined(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        $token = $this->inviteAndToken($user, 'grace@example.com');

        // No auth: this is what the link shows before signing in.
        $this->getJson("/api/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('email', 'grace@example.com')
            ->assertJsonPath('invited_by', 'Ada Lovelace')
            ->assertJsonPath('member_count', 1);
    }

    public function test_an_unknown_or_expired_token_previews_as_invalid(): void
    {
        $this->getJson('/api/invitations/nope')->assertNotFound();

        $user = User::factory()->create();
        $token = $this->inviteAndToken($user, 'grace@example.com');
        WorkspaceInvitation::first()->update(['expires_at' => now()->subDay()]);

        $this->getJson("/api/invitations/{$token}")->assertNotFound();
    }

    // ------------------------------------------------------------- accepting

    public function test_accepting_joins_the_workspace_and_shares_both_ways(): void
    {
        $owner = User::factory()->create(['name' => 'Ada']);
        $token = $this->inviteAndToken($owner, 'grace@example.com');
        $invitee = User::factory()->create(['email' => 'grace@example.com', 'name' => 'Grace']);

        $this->actingAs($invitee)->postJson("/api/workspace/invitations/{$token}/accept")
            ->assertOk();

        $this->assertSame($owner->fresh()->organisation_id, $invitee->fresh()->organisation_id);

        // The sharing boundary really did move — each now sees the other.
        $this->assertContains($owner->id, $invitee->fresh()->workspaceUserIds());
        $this->assertContains($invitee->id, $owner->fresh()->workspaceUserIds());
    }

    public function test_the_existing_members_are_told_who_joined(): void
    {
        $owner = User::factory()->create();
        $token = $this->inviteAndToken($owner, 'grace@example.com');
        $invitee = User::factory()->create(['email' => 'grace@example.com', 'name' => 'Grace Hopper']);

        $this->actingAs($invitee)->postJson("/api/workspace/invitations/{$token}/accept")->assertOk();

        $this->assertTrue(
            UserNotification::where('user_id', $owner->id)->where('type', 'workspace')->exists(),
            'Everyone already in the workspace should learn who gained access to their work.'
        );
    }

    public function test_only_the_invited_address_may_accept(): void
    {
        // The invitation is a grant of access to other people's work; a leaked
        // link must not be enough on its own.
        $owner = User::factory()->create();
        $token = $this->inviteAndToken($owner, 'grace@example.com');
        $stranger = User::factory()->create(['email' => 'someone.else@example.com']);

        $this->actingAs($stranger)->postJson("/api/workspace/invitations/{$token}/accept")
            ->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'grace@example.com'));

        $this->assertNull($stranger->fresh()->organisation_id);
    }

    public function test_accepting_requires_being_signed_in(): void
    {
        // Built directly rather than through the helper: that one signs in as
        // the inviter, and the session would still be live here.
        $owner = User::factory()->create();
        $organisation = $this->organisationWith($owner);
        $token = WorkspaceInvitation::generateToken();
        WorkspaceInvitation::create([
            'organisation_id' => $organisation->id,
            'invited_by_user_id' => $owner->id,
            'email' => 'grace@example.com',
            'token_hash' => WorkspaceInvitation::hash($token),
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson("/api/workspace/invitations/{$token}/accept")->assertUnauthorized();
    }

    public function test_an_invitation_cannot_be_accepted_twice(): void
    {
        $owner = User::factory()->create();
        $token = $this->inviteAndToken($owner, 'grace@example.com');
        $invitee = User::factory()->create(['email' => 'grace@example.com']);

        $this->actingAs($invitee)->postJson("/api/workspace/invitations/{$token}/accept")->assertOk();
        $this->actingAs($invitee)->postJson("/api/workspace/invitations/{$token}/accept")->assertNotFound();
    }

    public function test_someone_in_a_populated_workspace_must_leave_it_first(): void
    {
        // Moving them silently would cut them off from their current team and
        // hand their work to a new one.
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'grace@example.com']);
        $this->organisationWith($ownerB, $invitee);

        $token = $this->inviteAndToken($ownerA, 'grace@example.com');

        $this->actingAs($invitee)->postJson("/api/workspace/invitations/{$token}/accept")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Leave it first'));

        $this->assertSame($ownerB->fresh()->organisation_id, $invitee->fresh()->organisation_id);
    }

    public function test_a_lone_member_of_their_own_workspace_may_move(): void
    {
        $ownerA = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'grace@example.com']);
        $this->organisationWith($invitee);

        $token = $this->inviteAndToken($ownerA, 'grace@example.com');

        $this->actingAs($invitee)->postJson("/api/workspace/invitations/{$token}/accept")->assertOk();

        $this->assertSame($ownerA->fresh()->organisation_id, $invitee->fresh()->organisation_id);
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $owner = User::factory()->create();
        $token = $this->inviteAndToken($owner, 'grace@example.com');
        $invitee = User::factory()->create(['email' => 'grace@example.com']);
        WorkspaceInvitation::first()->update(['expires_at' => now()->subDay()]);

        $this->actingAs($invitee)->postJson("/api/workspace/invitations/{$token}/accept")->assertNotFound();
        $this->assertNull($invitee->fresh()->organisation_id);
    }

    // -------------------------------------------------------------- revoking

    public function test_a_revoked_invitation_stops_working(): void
    {
        $owner = User::factory()->create();
        $token = $this->inviteAndToken($owner, 'grace@example.com');
        $id = WorkspaceInvitation::first()->id;

        $this->actingAs($owner)->deleteJson("/api/workspace/invitations/{$id}")->assertOk();

        $this->getJson("/api/invitations/{$token}")->assertNotFound();
        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    public function test_a_member_cannot_revoke_someone_elses_invitation(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->organisationWith($owner, $member);

        $this->inviteAndToken($owner, 'grace@example.com');
        $id = WorkspaceInvitation::first()->id;

        $this->actingAs($member)->deleteJson("/api/workspace/invitations/{$id}")
            ->assertStatus(403);
    }

    public function test_an_invitation_from_another_workspace_is_not_found(): void
    {
        $outsider = User::factory()->create();
        $owner = User::factory()->create();
        $this->organisationWith($outsider);
        $this->inviteAndToken($owner, 'grace@example.com');

        $this->actingAs($outsider)
            ->deleteJson('/api/workspace/invitations/'.WorkspaceInvitation::first()->id)
            ->assertNotFound();
    }

    // --------------------------------------------------------------- members

    public function test_the_workspace_screen_lists_members_and_pending_invitations(): void
    {
        $owner = User::factory()->create(['name' => 'Ada']);
        $member = User::factory()->create(['name' => 'Grace']);
        $this->organisationWith($owner, $member);
        $this->inviteAndToken($owner, 'alan@example.com');

        $this->actingAs($owner)->getJson('/api/workspace')
            ->assertOk()
            ->assertJsonPath('is_owner', true)
            ->assertJsonPath('members.0.name', 'Ada')
            ->assertJsonPath('members.0.is_owner', true)
            ->assertJsonPath('members.1.name', 'Grace')
            ->assertJsonPath('members.1.is_owner', false)
            ->assertJsonPath('invitations.0.email', 'alan@example.com')
            // The token must never come back with the listing.
            ->assertJsonMissing(['token_hash']);
    }

    public function test_a_solo_account_sees_itself_as_a_workspace_of_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/workspace')
            ->assertOk()
            ->assertJsonPath('organisation', null)
            ->assertJsonPath('is_owner', true)
            ->assertJsonCount(1, 'members');
    }

    public function test_the_owner_can_remove_a_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->organisationWith($owner, $member);

        $this->actingAs($owner)->deleteJson("/api/workspace/members/{$member->id}")->assertOk();

        $this->assertNull($member->fresh()->organisation_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'workspace.member_removed']);
        $this->assertTrue(UserNotification::where('user_id', $member->id)->exists());
    }

    public function test_a_member_cannot_remove_anyone(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->organisationWith($owner, $member, $other);

        $this->actingAs($member)->deleteJson("/api/workspace/members/{$other->id}")
            ->assertStatus(403);

        $this->assertNotNull($other->fresh()->organisation_id);
    }

    public function test_the_owner_cannot_remove_themselves(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->organisationWith($owner, $member);

        $this->actingAs($owner)->deleteJson("/api/workspace/members/{$owner->id}")
            ->assertStatus(422);
    }

    public function test_a_member_can_leave_and_keeps_their_own_work(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->organisationWith($owner, $member);
        $saved = $member->savedRequests()->create([
            'name' => 'Mine', 'protocol' => 'rest', 'method' => 'GET', 'url' => 'https://api.example.com/x',
        ]);

        $this->actingAs($member)->postJson('/api/workspace/leave')->assertOk();

        $this->assertNull($member->fresh()->organisation_id);
        $this->assertSame([$saved->id], \App\Models\SavedRequest::inWorkspaceOf($member->fresh())->pluck('id')->all());
        // …and it is no longer visible to the workspace they left.
        $this->assertNotContains($saved->id, \App\Models\SavedRequest::inWorkspaceOf($owner->fresh())->pluck('id')->all());
    }

    public function test_the_owner_cannot_leave_while_others_remain(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->organisationWith($owner, $member);

        $this->actingAs($owner)->postJson('/api/workspace/leave')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'own this workspace'));
    }

    public function test_the_last_person_may_leave(): void
    {
        $owner = User::factory()->create();
        $this->organisationWith($owner);

        $this->actingAs($owner)->postJson('/api/workspace/leave')->assertOk();
        $this->assertNull($owner->fresh()->organisation_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'workspace.left']);
    }

    public function test_an_admin_created_organisation_falls_back_to_its_first_member_as_owner(): void
    {
        // Organisations predating owner_user_id must still have someone who can
        // remove members, or the team is stuck.
        $first = User::factory()->create();
        $second = User::factory()->create();
        $organisation = Organisation::create(['name' => 'Legacy', 'slug' => 'legacy']);
        $first->update(['organisation_id' => $organisation->id]);
        $second->update(['organisation_id' => $organisation->id]);

        $this->assertTrue($organisation->fresh()->isOwnedBy($first));
        $this->actingAs($first)->deleteJson("/api/workspace/members/{$second->id}")->assertOk();
    }

    public function test_every_route_requires_authentication(): void
    {
        $this->getJson('/api/workspace')->assertUnauthorized();
        $this->postJson('/api/workspace/invitations', ['email' => 'x@example.com'])->assertUnauthorized();
        $this->postJson('/api/workspace/leave')->assertUnauthorized();
        $this->deleteJson('/api/workspace/members/1')->assertUnauthorized();
    }
}
