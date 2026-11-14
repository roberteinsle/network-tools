<?php

namespace App\Mail;

use App\Services\Auth\MagicLink;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MagicLinkMail extends Mailable
{
    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Dein Anmeldelink für '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.magic-link', with: ['minutes' => MagicLink::MINUTES]);
    }
}
