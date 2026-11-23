<?php

namespace App\Mail;

use App\Modules\Core\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use NettSite\NettMail\Mail\NettMailMailable;

class PaymentReceiptMail extends NettMailMailable
{
    use Queueable, SerializesModels;

    public function __construct(public Document $payment, public string $emailSubject, public string $emailHtml)
    {
        $this->transactionalKey('payment_receipt')->trackOpens()->trackClicks();
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
        $media = $this->payment->getFirstMedia('payment_receipt');

        if ($media === null) {
            return [];
        }

        return [
            Attachment::fromPath($media->getPath())
                ->as('receipt-'.$this->payment->document_number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
