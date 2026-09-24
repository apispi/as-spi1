<?php

namespace App\Mail;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Carries the one copy of an invitation token that exists outside the database.
 *
 * It says what joining actually means in both directions, because "you've been
 * invited" alone would understate it: the recipient gains access to the
 * workspace's shared work, and hands over their own.
 */
class WorkspaceInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $inviter,
        public Organisation $organisation,
        public string $url,
        public Carbon $expiresAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->inviter->name.' invited you to the '.$this->organisation->name.' workspace on Spi',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.workspace-invitation',
            with: [
                'inviterName' => $this->inviter->name,
                'inviterEmail' => $this->inviter->email,
                'workspaceName' => $this->organisation->name,
                'url' => $this->url,
                'expiresAt' => $this->expiresAt->toDayDateTimeString(),
            ],
        );
    }
}
