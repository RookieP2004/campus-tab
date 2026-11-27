<?php

namespace App\Modules\Platform\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The one-time link a new platform admin uses to choose a password. */
class ConsolePasswordLinkMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly int $hours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set your Campus TAB platform console password');
    }

    public function content(): Content
    {
        $url = e($this->url);

        return new Content(htmlString: <<<HTML
            <p>A Campus TAB platform console account has been made for you. Choose your password with this link:</p>
            <p><a href="{$url}">{$url}</a></p>
            <p>The link works once, for {$this->hours} hours. You will set up two-step sign-in the first time you sign in.</p>
            HTML);
    }
}
