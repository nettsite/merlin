<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Invoice {{ $invoice->document_number }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; }
    .page { padding: 40px; }

    /* Header */
    .header-table { width: 100%; border-collapse: collapse; margin-bottom: 32px; }
    .company-name { font-size: 22px; font-weight: bold; color: #1a1a1a; }
    .invoice-title { font-size: 28px; font-weight: bold; color: #374151; text-align: right; }
    .invoice-meta { text-align: right; margin-top: 8px; }
    .invoice-meta table { margin-left: auto; border-collapse: collapse; }
    .invoice-meta td { padding: 2px 0 2px 16px; }
    .meta-label { color: #6b7280; font-weight: bold; text-align: right; }
    .meta-value { text-align: right; }

    /* Bill to / from */
    .parties-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    .section-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px; }
    .party-name { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
    .party-detail { color: #374151; line-height: 1.5; }

    /* Divider */
    .divider { border: none; border-top: 1px solid #e5e7eb; margin: 24px 0; }

    /* Line items table */
    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .items-table th {
        font-size: 9px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #6b7280;
        padding: 6px 8px;
        border-bottom: 2px solid #e5e7eb;
    }
    .items-table th.right { text-align: right; }
    .items-table td { padding: 8px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    .items-table td.right { text-align: right; }
    .items-table .desc { font-weight: 500; }
    .items-table .account { font-size: 9px; color: #9ca3af; margin-top: 2px; }

    /* Totals */
    .totals-table { width: 100%; border-collapse: collapse; }
    .totals-table td { padding: 3px 8px; }
    .totals-table .label { text-align: right; color: #6b7280; width: 80%; }
    .totals-table .amount { text-align: right; width: 20%; min-width: 80px; }
    .totals-table .grand-total td { font-size: 13px; font-weight: bold; border-top: 2px solid #1a1a1a; padding-top: 6px; }
    .totals-table .payment-row td { color: #374151; }
    .totals-table .balance-due td { font-size: 13px; font-weight: bold; border-top: 2px solid #1a1a1a; padding-top: 6px; }
    .totals-table .balance-paid td { color: #16a34a; }
    .paid-stamp { display: inline-block; border: 3px solid #16a34a; color: #16a34a; font-size: 18px; font-weight: bold; letter-spacing: 0.12em; padding: 4px 12px; border-radius: 4px; transform: rotate(-8deg); margin-top: 12px; }

    /* Notes */
    .notes-section { margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb; }
    .notes-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px; }

    /* Footer */
    .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
<div class="page">

@php
    $currencySymbol = \App\Modules\Purchasing\Services\ExchangeRateService::currencySymbol($invoice->currency);
    $company = app(\App\Modules\Core\Settings\CompanySettings::class);
    $companyName = $company->name ?: config('app.name');
    $logoPath = $company->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path) : null;
@endphp

    {{-- ===== Header ===== --}}
    <table class="header-table">
        <tr>
            <td style="vertical-align: top; width: 50%;">
                @if($logoPath && file_exists($logoPath))
                    <img src="{{ $logoPath }}" alt="{{ $companyName }}" style="max-height: 60px; max-width: 200px; margin-bottom: 6px; display: block;">
                @else
                    <div class="company-name">{{ $companyName }}</div>
                @endif
                @if($company->address_line_1)
                    <div style="font-size: 10px; color: #6b7280; margin-top: 4px; line-height: 1.6;">
                        <div>{{ $company->address_line_1 }}</div>
                        @if($company->address_line_2)<div>{{ $company->address_line_2 }}</div>@endif
                        @if($company->city || $company->postal_code)
                            <div>{{ implode(' ', array_filter([$company->city, $company->postal_code])) }}</div>
                        @endif
                        @if($company->country)<div>{{ $company->country }}</div>@endif
                        @if($company->phone)<div>{{ $company->phone }}</div>@endif
                        @if($company->email)<div>{{ $company->email }}</div>@endif
                        @if($company->tax_number)<div>Tax No: {{ $company->tax_number }}</div>@endif
                    </div>
                @endif
            </td>
            <td style="vertical-align: top; text-align: right; width: 50%;">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-meta">
                    <table>
                        <tr>
                            <td class="meta-label">Invoice #</td>
                            <td class="meta-value">{{ $invoice->document_number ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td class="meta-label">Date</td>
                            <td class="meta-value">{{ $invoice->issue_date?->format('d M Y') ?? '—' }}</td>
                        </tr>
                        @if($invoice->due_date)
                        <tr>
                            <td class="meta-label">Due Date</td>
                            <td class="meta-value">{{ $invoice->due_date->format('d M Y') }}</td>
                        </tr>
                        @endif
                        @if($invoice->reference)
                        <tr>
                            <td class="meta-label">Reference</td>
                            <td class="meta-value">{{ $invoice->reference }}</td>
                        </tr>
                        @endif
                    </table>
                </div>
            </td>
        </tr>
    </table>

    {{-- ===== Bill To ===== --}}
    @if($invoice->party)
    <table class="parties-table">
        <tr>
            <td style="vertical-align: top; width: 50%;">
                <div class="section-label">Bill To</div>
                <div class="party-name">{{ $invoice->party->business?->display_name ?? $invoice->party->displayName }}</div>
                @php
                    $address = $invoice->party->addresses()->where('is_primary', true)->where('is_active', true)->first()
                        ?? $invoice->party->addresses()->where('is_active', true)->first();
                @endphp
                @if($address)
                <div class="party-detail">
                    @if($address->line_1)<div>{{ $address->line_1 }}</div>@endif
                    @if($address->line_2)<div>{{ $address->line_2 }}</div>@endif
                    @if($address->city || $address->state_province)
                        <div>{{ implode(', ', array_filter([$address->city, $address->state_province])) }}@if($address->postal_code) {{ $address->postal_code }}@endif</div>
                    @endif
                    @if($address->country)<div>{{ $address->country }}</div>@endif
                </div>
                @endif
                @if($invoice->party->primary_email)
                    <div class="party-detail" style="margin-top: 4px;">{{ $invoice->party->primary_email }}</div>
                @endif
                @if($invoice->party->business?->tax_number)
                    <div class="party-detail">Tax No: {{ $invoice->party->business->tax_number }}</div>
                @endif
            </td>
        </tr>
    </table>
    @endif

    <hr class="divider">

    {{-- ===== Line Items ===== --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="text-align: left;">Description</th>
                <th class="right" style="width: 60px;">Qty</th>
                <th class="right" style="width: 90px;">Unit Price</th>
                <th class="right" style="width: 60px;">Tax %</th>
                <th class="right" style="width: 90px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $line)
            <tr>
                <td>
                    <div class="desc">{{ $line->description }}</div>
                </td>
                <td class="right">{{ rtrim(rtrim(number_format((float)$line->quantity, 4, '.', ''), '0'), '.') }}</td>
                <td class="right">{{ number_format((float)$line->unit_price, 2, '.', ',') }}</td>
                <td class="right">{{ $line->tax_rate > 0 ? number_format((float)$line->tax_rate, 0).'%' : '—' }}</td>
                <td class="right">{{ number_format((float)$line->line_total, 2, '.', ',') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center; color: #9ca3af; padding: 16px;">No line items.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    {{-- ===== Totals ===== --}}
    <table class="totals-table">
        <tr>
            <td class="label">Subtotal</td>
            <td class="amount">{{ $currencySymbol }}{{ number_format((float)$invoice->subtotal, 2, '.', ',') }}</td>
        </tr>
        @if((float)$invoice->tax_total > 0)
        <tr>
            <td class="label">Tax</td>
            <td class="amount">{{ $currencySymbol }}{{ number_format((float)$invoice->tax_total, 2, '.', ',') }}</td>
        </tr>
        @endif
        <tr class="grand-total">
            <td class="label">Invoice Total</td>
            <td class="amount">{{ $currencySymbol }}{{ number_format((float)$invoice->total, 2, '.', ',') }}</td>
        </tr>

        @php
            $payments = $invoice->childDocuments()
                ->wherePivot('relationship_type', 'payment_for')
                ->orderBy('issue_date')
                ->get();
        @endphp

        @if($payments->isNotEmpty())
            @foreach($payments as $payment)
            <tr class="payment-row">
                <td class="label">
                    Payment received {{ $payment->issue_date?->format('d M Y') }}
                    @if($payment->reference) - {{ $payment->reference }}@endif
                </td>
                <td class="amount">{{ $currencySymbol }}{{ number_format((float)$payment->total, 2, '.', ',') }}</td>
            </tr>
            @endforeach

            @if((float)$invoice->balance_due <= 0)
            <tr class="balance-paid">
                <td class="label">Balance Due</td>
                <td class="amount">{{ $currencySymbol }}0.00</td>
            </tr>
            @else
            <tr class="balance-due">
                <td class="label">Balance Due</td>
                <td class="amount">{{ $currencySymbol }}{{ number_format((float)$invoice->balance_due, 2, '.', ',') }}</td>
            </tr>
            @endif
        @endif
    </table>

    @if(in_array($invoice->status, ['paid']) )
    <div style="text-align: right; margin-top: 8px;">
        <span class="paid-stamp">PAID</span>
    </div>
    @endif

    {{-- ===== Notes ===== --}}
    @if($invoice->notes)
    <div class="notes-section">
        <div class="notes-label">Notes</div>
        <div>{{ $invoice->notes }}</div>
    </div>
    @endif

    {{-- ===== Terms ===== --}}
    @if($invoice->paymentTerm)
    <div class="notes-section">
        <div class="notes-label">Payment Terms</div>
        <div>{{ $invoice->paymentTerm->name }}</div>
    </div>
    @endif

    {{-- ===== Footer ===== --}}
    <div class="footer">
        {{ $companyName }} &bull; Generated {{ now()->format('d M Y') }}
    </div>

</div>
</body>
</html>
