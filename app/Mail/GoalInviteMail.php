<?php

namespace App\Mail;

use App\Models\SavingsGoal;
use App\Models\SavingsGoalMember;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "X invited you to save together for Y" — sent through the configured mailer (Resend SMTP). */
class GoalInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SavingsGoal $goal, public User $inviter, public SavingsGoalMember $member) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviterName()} invited you to save for \"{$this->goal->name}\"");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.goal-invite',
            with: [
                'inviterName' => $this->inviterName(),
                'goalName' => $this->goal->name,
                'target' => number_format((float) $this->goal->target_amount, 2),
                'saved' => number_format((float) $this->goal->current_amount, 2),
                'appLink' => 'salarytracker://goal-invites/'.$this->member->token,
                'webLink' => url('/goal-invites/'.$this->member->token),
                'email' => $this->member->email,
            ],
        );
    }

    private function inviterName(): string
    {
        return $this->inviter->profile?->nickname ?: ($this->inviter->profile?->full_name ?: $this->inviter->name);
    }
}
