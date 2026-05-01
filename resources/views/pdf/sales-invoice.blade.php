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

    /* Notes */
    .notes-section { margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb; }
    .notes-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px; }

    /* Footer */
    .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
<div class="page">

    {{-- ===== Header ===== --}}
    <table class="header-table">
        <tr>
            <td style="vertical-align: top; width: 50%;">
                <div class="company-name">{{ config('app.name') }}</div>
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
                    @if($line->account)
                        <div class="account">{{ $line->account->code }} {{ $line->account->name }}</div>
                    @endif
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
            <td class="amount">{{ $invoice->currency }} {{ number_format((float)$invoice->subtotal, 2, '.', ',') }}</td>
        </tr>
        @if((float)$invoice->tax_total > 0)
        <tr>
            <td class="label">Tax</td>
            <td class="amount">{{ $invoice->currency }} {{ number_format((float)$invoice->tax_total, 2, '.', ',') }}</td>
        </tr>
        @endif
        <tr class="grand-total">
            <td class="label">Total</td>
            <td class="amount">{{ $invoice->currency }} {{ number_format((float)$invoice->total, 2, '.', ',') }}</td>
        </tr>
    </table>

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
        {{ config('app.name') }} &bull; Generated {{ now()->format('d M Y') }}
    </div>

</div>
</body>
</html>
