<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the user their password was set, changed or reset. */
class PasswordChangedMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $schoolName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your Campus TAB password for {$this->schoolName} was changed");
    }

    public function content(): Content
    {
        $school = e($this->schoolName);

        return new Content(htmlString: <<<HTML
            <p>The password of your account at {$school} was just changed, and every device signed in to it has been signed out.</p>
            <p>If this was not you, tell your school office at once.</p>
            HTML);
    }
}
