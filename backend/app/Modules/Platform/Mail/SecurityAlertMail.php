<?php

namespace App\Modules\Platform\Mail;

use App\Modules\Platform\Models\PlatformAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells our team a school's security count jumped. Counts only, no names or content. */
class SecurityAlertMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly PlatformAlert $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Campus TAB security alert: '.$this->alert->school_code);
    }

    public function content(): Content
    {
        $message = e($this->alert->message);
        $url = e(config('identity.frontend_url').'/console/schools/'.$this->alert->school_code);

        return new Content(htmlString: <<<HTML
            <p>{$message}</p>
            <p>Open the school in the platform console: <a href="{$url}">{$url}</a></p>
            <p>The details are in the school's own audit log. Reading it needs a support grant from the school's Chief Admin.</p>
            HTML);
    }
}
