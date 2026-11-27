<?php

namespace App\Modules\Communication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An email notification. Generic on purpose: no names, marks or other
 * private details, only that there is something to read in the app.
 */
class NotificationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $text,
        public readonly string $schoolName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Campus TAB: '.$this->schoolName);
    }

    public function content(): Content
    {
        $text = e($this->text);
        $url = e((string) config('identity.frontend_url'));

        return new Content(htmlString: <<<HTML
            <p>{$text}</p>
            <p><a href="{$url}">{$url}</a></p>
            <p>You can change which notifications you get in Campus TAB, under your profile.</p>
            HTML);
    }
}
