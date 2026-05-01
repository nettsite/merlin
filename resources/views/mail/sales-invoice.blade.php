<x-mail::message>
# Invoice {{ $invoice->document_number }}

Please find your invoice attached.

**Amount Due:** {{ $invoice->currency }} {{ number_format((float)$invoice->total, 2) }}

@if($invoice->due_date)
**Payment Due:** {{ $invoice->due_date->format('d M Y') }}
@endif

@if($invoice->paymentTerm)
**Payment Terms:** {{ $invoice->paymentTerm->name }}
@endif

@if($invoice->reference)
**Reference:** {{ $invoice->reference }}
@endif

---

If you have any questions about this invoice, please don't hesitate to get in touch.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
