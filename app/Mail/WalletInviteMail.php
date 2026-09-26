<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "X invited you to use the account Y together" — sent through the configured mailer (Resend SMTP). */
class WalletInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Wallet $wallet, public User $inviter, public WalletMember $member) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviterName()} shared the account \"{$this->wallet->name}\" with you");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.wallet-invite',
            with: [
                'inviterName' => $this->inviterName(),
                'walletName' => $this->wallet->name,
                'appLink' => 'tandem://wallet-invites/'.$this->member->token,
                'webLink' => url('/wallet-invites/'.$this->member->token),
                'email' => $this->member->email,
            ],
        );
    }

    private function inviterName(): string
    {
        return $this->inviter->profile?->nickname ?: ($this->inviter->profile?->full_name ?: $this->inviter->name);
    }
}
