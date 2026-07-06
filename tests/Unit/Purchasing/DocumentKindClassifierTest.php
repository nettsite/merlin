<?php

use App\Modules\Purchasing\Services\DocumentKindClassifier;

beforeEach(function (): void {
    $this->classifier = new DocumentKindClassifier;
});

it('classifies typical invoice text as invoice', function (): void {
    $text = <<<'TEXT'
        TAX INVOICE
        Invoice No: INV-2024-001
        Bill To: Acme Corp

        Description       Quantity   Unit Price   Line Total
        Monthly hosting   1          1000.00      1000.00

        Subtotal: 1000.00
        VAT No: 4123456789
        Due Date: 2024-02-14
        TEXT;

    expect($this->classifier->classify($text))->toBe(DocumentKindClassifier::KIND_INVOICE);
});

it('classifies a PayPal receipt as a payment notification', function (): void {
    $text = <<<'TEXT'
        PayPal

        You sent a payment of $100.00 USD to Acme Hosting

        Receipt for your payment
        Transaction date: Jan 15, 2024
        TEXT;

    expect($this->classifier->classify($text))->toBe(DocumentKindClassifier::KIND_PAYMENT_NOTIFICATION);
});

it('classifies an FNB Connect email as a payment notification', function (): void {
    $text = <<<'TEXT'
        FNB Connect

        Payment Confirmation

        Proof of Payment
        Beneficiary: Domains CoZa
        Amount: R450.00
        TEXT;

    expect($this->classifier->classify($text))->toBe(DocumentKindClassifier::KIND_PAYMENT_NOTIFICATION);
});

it('defaults to invoice when the text is ambiguous', function (): void {
    expect($this->classifier->classify('Some unrelated text with no signals at all.'))
        ->toBe(DocumentKindClassifier::KIND_INVOICE);
});

// --- Real-world samples (FNB card alert + Payfast confirmation for the same purchase) ---

it('classifies an FNB card "reserved for purchase" alert as a payment notification', function (): void {
    $text = <<<'TEXT'
        Subject: FNB :-) R4085.00 reserved for purchase @ Payfast*host Africa Ptcap from FNB
        card a/c..533000 using card..1384. 8Jun 15:29
        From: inContact@fnb.co.za
        Dear valued customer
        • FNB :-) R4085.00 reserved for purchase @ Payfast*host Africa Ptcap from FNB card a/c..533000 using
        card..1384. Avail R11020. 8Jun 15:29
        Please do NOT reply to this message as it is sent from an unattended mailbox.
        TEXT;

    expect($this->classifier->classify($text))->toBe(DocumentKindClassifier::KIND_PAYMENT_NOTIFICATION);
});

it('classifies a Payfast "successfully paid" confirmation as a payment notification', function (): void {
    $text = <<<'TEXT'
        Subject: You successfully paid R 4,085.00 to Host Africa (Pty) Ltd
        From: Payfast <noreply@payfast.io>
        Payment Confirmation

        Hi William,
        You successfully paid R 4,085.00 to Host Africa (Pty) Ltd for the following transaction:
        Item Name: HOSTAFRICA purchase, Invoice ID #1680798
        Reference Number: 306790623
        TEXT;

    expect($this->classifier->classify($text))->toBe(DocumentKindClassifier::KIND_PAYMENT_NOTIFICATION);
});

it('still classifies a real tax invoice mentioning bank account details as an invoice, not a payment', function (): void {
    // Host Africa's own invoice lists its Standard Bank/FNB account numbers for
    // EFT payment — the presence of "FNB" alone must not tip this to payment_notification.
    $text = <<<'TEXT'
        Host Africa (Pty) Ltd
        Registration No 2008/019975/07
        VAT Number: 000004420255574
        Standard Bank
        Current Account: 281970777
        FNB
        Current Account 62666098332

        Tax Invoice #1680798
        Invoice Date: Monday, June 1st, 2026
        Due Date: Monday, June 8th, 2026

        Description                Quantity   Total
        Cloud Server (Reseller)    1          R1820.00

        Subtotal: R3552.17
        TEXT;

    expect($this->classifier->classify($text))->toBe(DocumentKindClassifier::KIND_INVOICE);
});
