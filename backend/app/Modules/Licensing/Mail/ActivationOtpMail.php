<?php

namespace App\Modules\Licensing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The activation code for a school's licence key. */
class ActivationOtpMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $schoolName,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Campus TAB activation code for {$this->schoolName}");
    }

    public function content(): Content
    {
        $school = e($this->schoolName);

        return new Content(htmlString: <<<HTML
            <p>Your code to activate Campus TAB for {$school} is:</p>
            <p style="font-size:24px;font-weight:bold;letter-spacing:4px">{$this->code}</p>
            <p>It works for {$this->minutes} minutes. If you did not ask for it, ignore this email.</p>
            HTML);
    }
}
