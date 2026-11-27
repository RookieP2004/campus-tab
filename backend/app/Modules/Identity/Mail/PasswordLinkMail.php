<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A one-time link to set (new account) or reset a password. */
class PasswordLinkMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $schoolName,
        public readonly string $purpose,
        public readonly string $validFor,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->purpose === 'set'
            ? "Set your Campus TAB password for {$this->schoolName}"
            : "Reset your Campus TAB password for {$this->schoolName}");
    }

    public function content(): Content
    {
        $school = e($this->schoolName);
        $url = e($this->url);
        $intro = $this->purpose === 'set'
            ? "An account has been made for you at {$school}. Choose your password with this link:"
            : "Someone asked to reset the password of your account at {$school}. Choose a new one with this link:";

        return new Content(htmlString: <<<HTML
            <p>{$intro}</p>
            <p><a href="{$url}">{$url}</a></p>
            <p>The link works once, for {$this->validFor}. If you did not expect this email, ignore it and your password stays as it is.</p>
            HTML);
    }
}
