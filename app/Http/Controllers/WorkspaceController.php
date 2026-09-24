<?php

namespace App\Http\Controllers;

use App\Mail\WorkspaceInvitationMail;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Self-serve team membership.
 *
 * Until now an organisation could only be assembled in the admin area, so a
 * user could not form a team at all — even though everything in the app is
 * already shared across one. This is the missing half: invite a colleague by
 * email, see who is in the workspace, and remove someone who has left.
 *
 * Joining is a real grant of access in both directions, so every route here
 * writes an audit event, and the invitee is told plainly what they are about
 * to share before they accept.
 */
class WorkspaceController extends Controller
{
    /** The workspace as the owner's screen renders it. */
    public function show(Request $request)
    {
        $user = $request->user();
        $organisation = $user->organisation;

        $members = User::whereIn('id', $user->workspaceUserIds())
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'created_at']);

        $ownerId = $organisation?->ownerId() ?? $user->id;

        return response()->json([
            'organisation' => $organisation ? [
                'id' => $organisation->id,
                'name' => $organisation->name,
                'slug' => $organisation->slug,
            ] : null,
            'is_owner' => $ownerId === $user->id,
            'members' => $members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'is_owner' => $member->id === $ownerId,
                'is_you' => $member->id === $user->id,
                'joined_at' => $member->created_at,
            ])->values(),
            'invitations' => $organisation
                ? WorkspaceInvitation::where('organisation_id', $organisation->id)
                    ->pending()->with('invitedBy:id,name')->orderBy('id')
                    ->get()->map->toClientArray()->values()
                : [],
        ]);
    }

    /**
     * Invite someone by email.
     *
     * A user with no organisation gets one created here — that is how a solo
     * account becomes a team, and it makes them its owner.
     */
    public function invite(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => 'required|string|email:filter|max:255',
        ]);

        $email = mb_strtolower(trim($validated['email']));

        if ($email === mb_strtolower($user->email)) {
            return response()->json(['message' => 'You are already in this workspace.'], 422);
        }

        $organisation = $user->organisation ?? $this->createWorkspaceFor($user);

        if (User::whereIn('id', $user->workspaceUserIds())->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json(['message' => 'That person is already in this workspace.'], 422);
        }

        $pending = WorkspaceInvitation::where('organisation_id', $organisation->id)->pending();

        if ((clone $pending)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json(['message' => 'There is already a pending invitation for that address.'], 422);
        }

        if ((clone $pending)->count() >= WorkspaceInvitation::MAX_PENDING) {
            return response()->json([
                'message' => 'Too many pending invitations ('.WorkspaceInvitation::MAX_PENDING.'). Revoke one first.',
            ], 422);
        }

        $token = WorkspaceInvitation::generateToken();

        $invitation = WorkspaceInvitation::create([
            'organisation_id' => $organisation->id,
            'invited_by_user_id' => $user->id,
            'email' => $email,
            'token_hash' => WorkspaceInvitation::hash($token),
            'expires_at' => now()->addDays(WorkspaceInvitation::LIFETIME_DAYS),
        ]);

        $url = url('/invite/'.$token);

        // A mail failure must not lose the invitation — the link can be copied
        // from the workspace screen instead.
        try {
            Mail::to($email)->send(new WorkspaceInvitationMail($user, $organisation, $url, $invitation->expires_at));
        } catch (\Throwable $e) {
            Log::warning('Workspace invitation email failed', ['error' => $e->getMessage()]);
        }

        AuditEvent::record('workspace.invited', $user, $request, [
            'organisation_id' => $organisation->id,
            'email' => $email,
        ]);

        return response()->json(
            $invitation->load('invitedBy:id,name')->toClientArray() + ['url' => $url],
            201
        );
    }

    public function revokeInvitation(Request $request, int $id)
    {
        $user = $request->user();
        $organisation = $user->organisation;

        if (! $organisation) {
            return response()->json(['message' => 'You are not in a workspace.'], 404);
        }

        $invitation = WorkspaceInvitation::where('organisation_id', $organisation->id)->findOrFail($id);

        // The owner may revoke anything; anyone else only what they sent.
        if (! $organisation->isOwnedBy($user) && $invitation->invited_by_user_id !== $user->id) {
            return response()->json(['message' => 'Only the workspace owner can revoke that invitation.'], 403);
        }

        $invitation->delete();

        AuditEvent::record('workspace.invitation_revoked', $user, $request, [
            'organisation_id' => $organisation->id,
            'email' => $invitation->email,
        ]);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /**
     * What an invitation link shows before sign-in: who is inviting, into what,
     * and — the part that matters — what accepting will share.
     */
    public function showInvitation(string $token)
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json(['message' => 'This invitation is no longer valid.'], 404);
        }

        $invitation->load(['organisation:id,name', 'invitedBy:id,name']);

        return response()->json([
            'email' => $invitation->email,
            'workspace' => $invitation->organisation?->name,
            'invited_by' => $invitation->invitedBy?->name,
            'expires_at' => $invitation->expires_at,
            'member_count' => User::where('organisation_id', $invitation->organisation_id)->count(),
        ]);
    }

    /**
     * Accept an invitation.
     *
     * Deliberately strict: the signed-in account must be the address the
     * invitation was sent to, and someone already in a workspace with other
     * people must leave it first. Moving them silently would both cut them off
     * from their current team and hand their existing work to a new one.
     */
    public function acceptInvitation(Request $request, string $token)
    {
        $user = $request->user();
        $invitation = WorkspaceInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json(['message' => 'This invitation is no longer valid.'], 404);
        }

        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            return response()->json([
                'message' => 'This invitation was sent to '.$invitation->email.'. Sign in with that account to accept it.',
            ], 403);
        }

        if ($user->organisation_id === $invitation->organisation_id) {
            return response()->json(['message' => 'You are already in this workspace.'], 422);
        }

        if ($user->organisation_id !== null && $user->organisation?->users()->count() > 1) {
            return response()->json([
                'message' => 'You are already in a workspace with other people. Leave it first, then accept this invitation.',
            ], 422);
        }

        $user->update(['organisation_id' => $invitation->organisation_id]);

        $invitation->update([
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ]);

        AuditEvent::record('workspace.joined', $user, $request, [
            'organisation_id' => $invitation->organisation_id,
        ]);

        // Everyone already in the workspace should know who just gained access
        // to their work.
        UserNotification::recordForWorkspace(
            $user->fresh(),
            'workspace',
            $user->name.' joined the workspace',
            $user->email.' accepted an invitation and can now see the workspace\'s shared requests, collections and environments.',
            '/profile'
        );

        return response()->json([
            'message' => 'You have joined '.$invitation->organisation?->name.'.',
            'organisation' => [
                'id' => $invitation->organisation_id,
                'name' => $invitation->organisation?->name,
            ],
        ]);
    }

    /** Remove someone from the workspace. The owner may remove anyone but themselves. */
    public function removeMember(Request $request, int $id)
    {
        $user = $request->user();
        $organisation = $user->organisation;

        if (! $organisation) {
            return response()->json(['message' => 'You are not in a workspace.'], 404);
        }

        if (! $organisation->isOwnedBy($user)) {
            return response()->json(['message' => 'Only the workspace owner can remove members.'], 403);
        }

        if ($id === $user->id) {
            return response()->json([
                'message' => 'You own this workspace. Transfer it or remove the others first.',
            ], 422);
        }

        $member = User::where('organisation_id', $organisation->id)->findOrFail($id);
        $member->update(['organisation_id' => null]);

        AuditEvent::record('workspace.member_removed', $user, $request, [
            'organisation_id' => $organisation->id,
            'removed_user_id' => $member->id,
        ]);

        UserNotification::record(
            $member->id,
            'workspace',
            'You were removed from '.$organisation->name,
            'Your own requests, collections and environments are unaffected — they are yours and stay with you.',
            '/profile'
        );

        return response()->json(['message' => $member->name.' was removed from the workspace.']);
    }

    /** Leave the workspace. The owner cannot, while anyone else is still in it. */
    public function leave(Request $request)
    {
        $user = $request->user();
        $organisation = $user->organisation;

        if (! $organisation) {
            return response()->json(['message' => 'You are not in a workspace.'], 404);
        }

        if ($organisation->isOwnedBy($user) && $organisation->users()->count() > 1) {
            return response()->json([
                'message' => 'You own this workspace. Remove the other members first, or ask an administrator to transfer it.',
            ], 422);
        }

        $user->update(['organisation_id' => null]);

        AuditEvent::record('workspace.left', $user, $request, [
            'organisation_id' => $organisation->id,
        ]);

        return response()->json(['message' => 'You have left '.$organisation->name.'.']);
    }

    /**
     * Turn a solo account into a workspace, named after the person creating it.
     * Their existing work does not move — it simply becomes visible to whoever
     * they go on to invite.
     */
    private function createWorkspaceFor(User $user): Organisation
    {
        $name = trim($user->name) !== '' ? $user->name."'s workspace" : 'My workspace';

        $organisation = Organisation::create([
            'name' => mb_substr($name, 0, 255),
            'slug' => Organisation::uniqueSlug($name),
            'owner_user_id' => $user->id,
        ]);

        $user->update(['organisation_id' => $organisation->id]);

        // The relation was read (as null) a moment ago and is cached on this
        // instance; leaving it stale would have the next call create a second
        // workspace for the same person.
        $user->setRelation('organisation', $organisation);
        $user->refreshWorkspaceUserIds();

        return $organisation;
    }
}
