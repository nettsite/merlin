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
