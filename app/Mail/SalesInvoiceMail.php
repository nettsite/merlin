<?php

namespace App\Mail;

use App\Modules\Purchasing\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalesInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Document $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice '.$this->invoice->document_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sales-invoice',
            with: ['invoice' => $this->invoice->load('paymentTerm')],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $media = $this->invoice->getFirstMedia('invoice_pdf');

        if ($media === null) {
            return [];
        }

        return [
            Attachment::fromPath($media->getPath())
                ->as('invoice-'.$this->invoice->document_number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
