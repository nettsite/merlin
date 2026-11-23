<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Receipt {{ $payment->document_number }}</title>
<style>
    @page { margin: 0; }
    * { margin: 0; padding: 0; }
    body { margin: 45px 30px 30px; font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #000; line-height: 1.55; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }

    .muted { color: #a3a3a3; line-height: 1.7; }
    .client-label { margin-bottom: 2px; }
    .client-name { font-size: 16px; color: #2a8bad; margin-bottom: 2px; }

    .info-box { background: #2a8bad; color: #fff; border-radius: 10px; padding: 12px 16px 14px; }
    .info-box td { padding: 2px 0; }
    .info-box .value { text-align: right; }

    .items th { background: #2a8bad; color: #fff; font-weight: normal; text-align: left; padding: 11px 14px; }
    .items th.right, .items td.right { text-align: right; }
    .items td { background: #f5f5f5; padding: 10px 14px; }

    .footer { margin-top: 40px; }
</style>
</head>
<body>

@php
    $currencySymbol = \App\Modules\Purchasing\Services\ExchangeRateService::currencySymbol($payment->currency);
    $money = fn ($amount): string => $currencySymbol.number_format((float) $amount, 2, '.', ',');

    $company = app(\App\Modules\Core\Settings\CompanySettings::class);
    $companyName = $company->name ?: config('app.name');
    $logoPath = $company->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path) : null;
    $companyLocality = trim(implode(', ', array_filter([$company->city, $company->state_province])).' '.$company->postal_code);

    $business = $payment->party?->business;
    $address = $payment->party?->addresses()->where('is_active', true)->orderByDesc('is_primary')->first();
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
            @if($company->email)<div>{{ $company->email }}</div>@endif
            @if($company->phone)<div>{{ $company->phone }}</div>@endif
        </td>
        <td style="width: 27%;" class="muted">
            @if($company->address_line_1)<div>{{ $company->address_line_1 }}</div>@endif
            @if($company->address_line_2)<div>{{ $company->address_line_2 }}</div>@endif
            @if($companyLocality)<div>{{ $companyLocality }}</div>@endif
            @if($company->country)<div>{{ $company->country }}</div>@endif
        </td>
    </tr>
</table>

{{-- ===== Client + receipt summary ===== --}}
<table style="margin-top: 30px;">
    <tr>
        <td style="width: 62%; padding: 8px 0 0 16px;">
            <div class="client-label">Received from:</div>
            <div class="client-name">{{ $business?->display_name ?? $payment->party?->displayName }}</div>
            @if($address)
                @if($address->line_1)<div>{{ $address->line_1 }}</div>@endif
                @if($address->line_2)<div>{{ $address->line_2 }}</div>@endif
            @endif
        </td>
        <td style="width: 38%;">
            <div class="info-box">
                <table>
                    <tr><td>Receipt Number</td><td class="value">{{ $payment->document_number }}</td></tr>
                    <tr><td>Date</td><td class="value">{{ $payment->issue_date?->format('d/m/Y') }}</td></tr>
                    <tr><td>Amount Received</td><td class="value">{{ $money($payment->total) }}</td></tr>
                    @if($payment->reference)
                        <tr><td>Reference</td><td class="value">{{ $payment->reference }}</td></tr>
                    @endif
                </table>
            </div>
        </td>
    </tr>
</table>

{{-- ===== Invoices paid ===== --}}
<table class="items" style="margin-top: 34px;">
    <thead>
        <tr>
            <th style="width: 24%;">Invoice</th>
            <th style="width: 18%;">Date</th>
            <th style="width: 18%;">Due Date</th>
            <th class="right" style="width: 13%;">Amount</th>
            <th class="right" style="width: 13%;">Paid</th>
            <th class="right" style="width: 14%;">Due</th>
        </tr>
    </thead>
    <tbody>
        @forelse($invoices as $invoice)
            <tr>
                <td>{{ $invoice->document_number }}</td>
                <td>{{ $invoice->issue_date?->format('d/m/Y') }}</td>
                <td>{{ $invoice->due_date?->format('d/m/Y') }}</td>
                <td class="right">{{ $money($invoice->total) }}</td>
                <td class="right">{{ $money($invoice->amount_paid) }}</td>
                <td class="right">{{ $money(max(0, (float) $invoice->balance_due)) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6">—</td>
            </tr>
        @endforelse
    </tbody>
    @if($invoices->isNotEmpty())
        <tfoot>
            <tr>
                <td colspan="3" style="background: #f5f5f5; padding: 10px 14px; font-weight: bold;">Total</td>
                <td class="right" style="background: #f5f5f5; padding: 10px 14px; font-weight: bold;">{{ $money($invoices->sum('total')) }}</td>
                <td class="right" style="background: #f5f5f5; padding: 10px 14px; font-weight: bold;">{{ $money($invoices->sum('amount_paid')) }}</td>
                <td class="right" style="background: #f5f5f5; padding: 10px 14px; font-weight: bold;">{{ $money($invoices->sum(fn ($i) => max(0, (float) $i->balance_due))) }}</td>
            </tr>
        </tfoot>
    @endif
</table>

<div class="footer">Thank you for your payment.</div>

</body>
</html>
