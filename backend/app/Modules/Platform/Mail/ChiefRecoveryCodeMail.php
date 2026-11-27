<?php

namespace App\Modules\Platform\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The one-time code for restoring a sole Chief Admin's sign-in, sent to the school's registered contact. */
class ChiefRecoveryCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $schoolName,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Campus TAB: Chief Admin sign-in recovery for {$this->schoolName}");
    }

    public function content(): Content
    {
        $school = e($this->schoolName);

        return new Content(htmlString: <<<HTML
            <p>Campus TAB support has started restoring the Chief Admin's two-step sign-in for {$school}, because the authenticator and the recovery codes were lost.</p>
            <p>If you asked for this, read this code to the Campus TAB support agent: <strong>{$this->code}</strong>. It works once, for {$this->minutes} minutes.</p>
            <p>If you did not ask for this, do not share the code, and tell your Chief Admin.</p>
            HTML);
    }
}
