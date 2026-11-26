<?php

namespace App\Mail;

use App\Modules\Core\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use NettSite\NettMail\Mail\NettMailMailable;

class SalesInvoiceMail extends NettMailMailable
{
    use Queueable, SerializesModels;

    public function __construct(public Document $invoice, public string $emailSubject, public string $emailHtml)
    {
        $this->transactionalKey('sales_invoice')->trackOpens()->trackClicks();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->emailHtml,
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $media = $this->invoice->getFirstMedia('invoice_pdf');

        // The media row can outlive the file (storage cleared/moved outside the app);
        // Symfony's mailer fatals opaquely trying to read a path that doesn't exist, so
        // send without the attachment rather than fail the whole email.
        if ($media === null || ! is_file($media->getPath())) {
            return [];
        }

        return [
            Attachment::fromPath($media->getPath())
                ->as('invoice-'.$this->invoice->document_number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
