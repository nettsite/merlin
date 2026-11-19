<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Invoice {{ $invoice->document_number }}</title>
<style>
    /* DomPDF applies `*` to the page box too, so the page margin lives on body. */
    @page { margin: 0; }
    * { margin: 0; padding: 0; }
    body { margin: 45px 30px 30px; font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #000; line-height: 1.55; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }

    .muted { color: #a3a3a3; line-height: 1.7; }
    .accent { color: #2a8bad; }
    .icon { width: 12px; height: 12px; margin-right: 5px; position: relative; top: 2px; }

    .client-label { margin-bottom: 2px; }
    .client-name { font-size: 16px; color: #2a8bad; margin-bottom: 2px; }

    .info-box { background: #2a8bad; color: #fff; border-radius: 10px; padding: 12px 16px 14px; }
    .info-box td { padding: 2px 0; }
    .info-box .value { text-align: right; }

    .items th { background: #2a8bad; color: #fff; font-weight: normal; text-align: left; padding: 11px 14px; }
    .items th.right, .items td.right { text-align: right; }
    .items td { background: #f5f5f5; padding: 10px 14px; }

    .totals td { padding: 3px 14px; }
    .totals .amount { text-align: right; }
    .totals .balance td { background: #2a8bad; color: #fff; padding: 5px 14px; }

    .terms-label { }
    .bank { margin-top: 40px; border-top: 2px solid #2a8bad; padding-top: 10px; }
    .bank-title { color: #2a8bad; margin-bottom: 6px; }
    .bank td { padding-right: 16px; }
    .bank-label { color: #737373; font-size: 10.5px; }
    .footer { margin-top: 24px; }
</style>
</head>
<body>

@php
    $currencySymbol = \App\Modules\Purchasing\Services\ExchangeRateService::currencySymbol($invoice->currency);
    $money = fn ($amount): string => $currencySymbol.number_format((float) $amount, 2, '.', ',');
    $quantity = fn ($value): string => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

    /* Ninja stores terms/footer as HTML (<p>..</p>) or plain text with newlines; print both as escaped text. */
    $plainText = function (?string $value): string {
        $text = preg_replace('/<\s*br\s*\/?>|<\/p>/i', "\n", (string) $value);

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    };

    $company = app(\App\Modules\Core\Settings\CompanySettings::class);
    $billing = app(\App\Modules\Billing\Settings\BillingSettings::class);
    $companyName = $company->name ?: config('app.name');
    $logoPath = $company->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path) : null;
    $companyLocality = trim(implode(', ', array_filter([$company->city, $company->state_province])).' '.$company->postal_code);

    $business = $invoice->party?->business;
    $address = $invoice->party?->addresses()->where('is_active', true)->orderByDesc('is_primary')->first();
    $clientLocality = $address
        ? trim(implode(', ', array_filter([$address->city, $address->state_province])).' '.$address->postal_code)
        : '';

    $terms = $plainText($invoice->terms) ?: $plainText($billing->default_invoice_terms);
    $footer = $plainText($invoice->footer) ?: $plainText($billing->default_invoice_footer);
    $notes = $plainText($invoice->notes);

    $bankDetails = array_filter([
        'Bank' => $company->bank_name,
        'Account Name' => $company->bank_account_name,
        'Account Number' => $company->bank_account_number,
        'Branch Code' => $company->bank_branch_code,
    ]);

    /* Heroicons outline paths as inline SVG: DomPDF has no emoji/icon font. */
    $icon = fn (string $path): string => 'data:image/svg+xml;base64,'.base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#a3a3a3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="'.$path.'"/></svg>'
    );
    $emailIcon = $icon('M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75');
    $websiteIcon = $icon('M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418');
    $phoneIcon = $icon('M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z');

    /* Ninja-era footers carry bank details; the EFT block replaces them, so drop those lines. */
    if ($bankDetails) {
        $footer = trim(implode("\n", array_filter(
            explode("\n", $footer),
            fn (string $line): bool => preg_match('/bank|account\s*(no|number)|branch\s*code/i', $line) !== 1,
        )));
    }
@endphp

{{-- ===== Header: logo, company contact, company address ===== --}}
<table>
    <tr>
        <td style="width: 46%;">
            @if($logoPath && file_exists($logoPath))
                <img src="{{ $logoPath }}" alt="{{ $companyName }}" style="max-height: 62px; max-width: 250px;">
            @else
                <div style="font-size: 22px; font-weight: bold;">{{ $companyName }}</div>
            @endif
        </td>
        <td style="width: 27%;" class="muted">
            <div>{{ $companyName }}</div>
            @if($company->website)<div><img src="{{ $websiteIcon }}" class="icon" alt="">{{ $company->website }}</div>@endif
            @if($company->email)<div><img src="{{ $emailIcon }}" class="icon" alt="">{{ $company->email }}</div>@endif
            @if($company->phone)<div><img src="{{ $phoneIcon }}" class="icon" alt="">{{ $company->phone }}</div>@endif
        </td>
        <td style="width: 27%;" class="muted">
            @if($company->address_line_1)<div>{{ $company->address_line_1 }}</div>@endif
            @if($company->address_line_2)<div>{{ $company->address_line_2 }}</div>@endif
            @if($companyLocality)<div>{{ $companyLocality }}</div>@endif
            @if($company->country)<div>{{ $company->country }}</div>@endif
        </td>
    </tr>
</table>

{{-- ===== Client + invoice summary ===== --}}
<table style="margin-top: 30px;">
    <tr>
        <td style="width: 62%; padding: 8px 0 0 16px;">
            <div class="client-label">Invoice issued to:</div>
            <div class="client-name">{{ $business?->display_name ?? $invoice->party?->displayName }}</div>
            @if($address)
                @if($address->line_1)<div>{{ $address->line_1 }}</div>@endif
                @if($address->line_2)<div>{{ $address->line_2 }}</div>@endif
                @if($clientLocality)<div>{{ $clientLocality }}</div>@endif
            @endif
            @if($business?->tax_number)<div>{{ $business->tax_number }}</div>@endif
            @if($business?->registration_number)<div>{{ $business->registration_number }}</div>@endif
        </td>
        <td style="width: 38%;">
            <div class="info-box">
                <table>
                    <tr><td>Invoice Number</td><td class="value">{{ $invoice->document_number }}</td></tr>
                    <tr><td>Invoice Date</td><td class="value">{{ $invoice->issue_date?->format('F j, Y') }}</td></tr>
                    @if($invoice->due_date)
                        <tr><td>Due Date</td><td class="value">{{ $invoice->due_date->format('F j, Y') }}</td></tr>
                    @endif
                    <tr><td>Invoice Total</td><td class="value">{{ $money($invoice->total) }}</td></tr>
                </table>
            </div>
        </td>
    </tr>
</table>

{{-- ===== Line items ===== --}}
<table class="items" style="margin-top: 34px;">
    <thead>
        <tr>
            <th style="width: 57%;">Description</th>
            <th style="width: 14%;">Unit Cost</th>
            <th style="width: 12%;">Quantity</th>
            <th class="right" style="width: 17%;">Line Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td>{{ $money($line->unit_price) }}</td>
                <td>{{ $quantity($line->quantity) }}</td>
                <td class="right">{{ $money($line->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- ===== Terms + totals ===== --}}
<table style="margin-top: 30px;">
    <tr>
        <td style="width: 62%; padding: 0 24px 0 12px;">
            @if($notes)
                <div style="margin-bottom: 12px;">{!! nl2br(e($notes)) !!}</div>
            @endif
            @if($terms)
                <div class="terms-label">Invoice Terms:</div>
                <div>{!! nl2br(e($terms)) !!}</div>
            @endif
        </td>
        <td style="width: 38%;">
            <table class="totals">
                <tr><td>Net</td><td class="amount">{{ $money($invoice->subtotal) }}</td></tr>
                <tr><td>Subtotal</td><td class="amount">{{ $money($invoice->subtotal) }}</td></tr>
                @if((float) $invoice->tax_total > 0)
                    <tr><td>Tax</td><td class="amount">{{ $money($invoice->tax_total) }}</td></tr>
                @endif
                <tr><td>Total</td><td class="amount">{{ $money($invoice->total) }}</td></tr>
                <tr><td>Paid to Date</td><td class="amount">{{ $money($invoice->amount_paid) }}</td></tr>
                <tr class="balance"><td>Balance Due</td><td class="amount">{{ $money(max(0, (float) $invoice->balance_due)) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if($bankDetails)
    <div class="bank">
        <div class="bank-title">Banking Details for EFT Payment</div>
        <table>
            <tr>
                @foreach($bankDetails as $label => $value)
                    <td><span class="bank-label">{{ $label }}</span><br>{{ $value }}</td>
                @endforeach
            </tr>
        </table>
    </div>
@endif

@if($footer)
    <div class="footer">{!! nl2br(e($footer)) !!}</div>
@endif

</body>
</html>
