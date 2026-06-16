<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ModelHealthAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $alertSubject, public string $alertBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<pre style="font-family:monospace;white-space:pre-wrap">'.e($this->alertBody).'</pre>',
        );
    }
}
