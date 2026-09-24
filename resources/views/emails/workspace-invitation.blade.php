@component('mail::message')
# You have been invited to a shared workspace

**{{ $inviterName }}** ({{ $inviterEmail }}) has invited you to join the
**{{ $workspaceName }}** workspace on Spi.

A workspace is shared in both directions. Once you join:

- You will be able to see and edit the workspace's saved requests, collections,
  environments, monitors and reports.
- The other members will be able to see and edit yours.

@component('mail::button', ['url' => $url])
Review the invitation
@endcomponent

You will be asked to sign in first, and shown what you are joining before
anything changes. This link works once and expires on **{{ $expiresAt }}**.

If you were not expecting this invitation, you can ignore this email — nothing
is shared until you accept.

Thanks,<br>
The Spi team
@endcomponent
