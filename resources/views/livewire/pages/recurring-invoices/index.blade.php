<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Services\RecurringInvoiceService;
use App\Modules\Core\Models\Party;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Services\ExchangeRateService;
use App\Modules\Core\Settings\CurrencySettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    #[Validate('required|uuid|exists:parties,id')]
    public string $clientId = '';

    #[Validate('required|string')]
    public string $frequency = 'monthly';

    #[Validate('required|integer|min:1|max:28')]
    public int $billingPeriodDay = 1;

    #[Validate('required|date')]
    public string $startDate = '';

    #[Validate('nullable|date|after_or_equal:startDate')]
    public string $endDate = '';

    #[Validate('nullable|uuid|exists:payment_terms,id')]
    public string $paymentTermId = '';

    #[Validate('nullable|string|max:2000')]
    public string $notes = '';

    /** @var array<int, array<string, mixed>> */
    public array $formLines = [];

    public string $activeTab = 'details';

    public function mount(): void
    {
        $this->authorize('viewAny', RecurringInvoice::class);
    }

    public function create(): void
    {
        $this->authorize('create', RecurringInvoice::class);
        $this->reset(['clientId', 'paymentTermId', 'notes', 'endDate', 'formLines']);
        $this->frequency = 'monthly';
        $this->billingPeriodDay = 1;
        $this->startDate = now()->toDateString();
        $this->activeTab = 'details';
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $template = RecurringInvoice::with('lines')->findOrFail($id);
        $this->authorize('update', $template);

        $this->clientId = $template->client_id;
        $this->frequency = $template->frequency->value;
        $this->billingPeriodDay = $template->billing_period_day;
        $this->startDate = $template->start_date->toDateString();
        $this->endDate = $template->end_date?->toDateString() ?? '';
        $this->paymentTermId = $template->payment_term_id ?? '';
        $this->notes = $template->notes ?? '';
        $this->activeTab = 'details';

        $this->formLines = $template->lines->map(fn ($line) => [
            'description' => $line->description,
            'account_id' => $line->account_id ?? '',
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'discount_percent' => (string) $line->discount_percent,
            'tax_rate' => (string) ($line->tax_rate ?? ''),
        ])->all();
    }

    protected function store(): void
    {
        $this->validate();

        app(RecurringInvoiceService::class)->createTemplate([
            'client_id' => $this->clientId,
            'frequency' => $this->frequency,
            'billing_period_day' => $this->billingPeriodDay,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate ?: null,
            'payment_term_id' => $this->paymentTermId ?: null,
            'notes' => $this->notes ?: null,
            'lines' => $this->formLines,
        ]);
    }

    protected function update(): void
    {
        $this->validate();

        $template = RecurringInvoice::findOrFail($this->editingId);
        $this->authorize('update', $template);

        $template->update([
            'client_id' => $this->clientId,
            'frequency' => $this->frequency,
            'billing_period_day' => $this->billingPeriodDay,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate ?: null,
            'payment_term_id' => $this->paymentTermId ?: null,
            'notes' => $this->notes ?: null,
        ]);

        // Sync lines: replace all
        $template->lines()->delete();
        foreach ($this->formLines as $i => $line) {
            $template->lines()->create([
                'line_number' => $i + 1,
                'description' => $line['description'],
                'account_id' => $line['account_id'] ?: null,
                'quantity' => $line['quantity'] ?? 1,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount_percent' => $line['discount_percent'] ?? 0,
                'tax_rate' => $line['tax_rate'] !== '' ? $line['tax_rate'] : null,
            ]);
        }
    }

    protected function performDelete(string $id): void
    {
        $template = RecurringInvoice::findOrFail($id);
        $this->authorize('delete', $template);
        $template->lines()->delete();
        $template->delete();
    }

    // -------------------------------------------------------------------------
    // Line management
    // -------------------------------------------------------------------------

    public function addFormLine(): void
    {
        $this->formLines[] = [
            'description' => '',
            'account_id' => '',
            'quantity' => '1',
            'unit_price' => '',
            'discount_percent' => '0',
            'tax_rate' => '15',
        ];
    }

    public function removeFormLine(int $index): void
    {
        array_splice($this->formLines, $index, 1);
        $this->formLines = array_values($this->formLines);
    }

    public function with(): array
    {
        $historyInvoices = collect();

        if ($this->showForm && $this->editingId) {
            $historyInvoices = Document::salesInvoices()
                ->where('metadata->recurring_invoice_id', $this->editingId)
                ->with('party.business')
                ->latest('issue_date')
                ->get();
        }

        return [
            'rows' => RecurringInvoice::with('client.business')
                ->when($this->search, fn ($q) => $q->whereHas('client.business', fn ($b) => $b
                    ->where('trading_name', 'like', "%{$this->search}%")
                    ->orWhere('legal_name', 'like', "%{$this->search}%")
                ))
                ->orderBy('next_invoice_date')
                ->paginate($this->perPage),
            'clients' => Party::clients()->with('business')->get(),
            'paymentTerms' => PaymentTerm::orderBy('name')->get(['id', 'name']),
            'accounts' => Account::postable()->income()->active()->orderBy('code')->get(['id', 'code', 'name']),
            'frequencies' => RecurringFrequency::cases(),
            'historyInvoices' => $historyInvoices,
            'currencySymbol' => ExchangeRateService::currencySymbol(
                app(CurrencySettings::class)->base_currency
            ),
        ];
    }
}; ?>

<div>

{{-- Page header --}}
<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Recurring Invoices</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Automated invoice templates</p>
    </div>
    @can('create', \App\Modules\Billing\Models\RecurringInvoice::class)
        <flux:button wire:click="create" variant="primary" icon="plus" size="sm">New Template</flux:button>
    @endcan
</div>

{{-- Flash --}}
@if(session('success'))
    <div class="mx-6 mt-4 px-4 py-3 rounded bg-green-50 border border-green-200 text-sm text-success">
        {{ session('success') }}
    </div>
@endif

{{-- Table --}}
<x-crud.table title="Recurring Invoices" description="Automated billing templates that generate invoices on a schedule." :rows="$rows">
    <x-slot name="search">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search clients…" icon="magnifying-glass" size="sm" class="max-w-xs" />
    </x-slot>

    <x-slot name="head">
        <tr>
            <x-crud.th column="client" :sortBy="$sortBy" :sortDir="$sortDir">Client</x-crud.th>
            <x-crud.th column="frequency" :sortBy="$sortBy" :sortDir="$sortDir">Frequency</x-crud.th>
            <x-crud.th column="next_invoice_date" :sortBy="$sortBy" :sortDir="$sortDir">Next Date</x-crud.th>
            <x-crud.th column="status" :sortBy="$sortBy" :sortDir="$sortDir">Status</x-crud.th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-ink-muted uppercase tracking-wide"></th>
        </tr>
    </x-slot>

    @forelse($rows as $template)
        <tr class="group hover:bg-surface-alt transition-colors">
            <td class="px-4 py-3 font-medium text-ink">
                {{ $template->client?->business?->display_name ?? '—' }}
            </td>
            <td class="px-4 py-3 text-ink-soft">
                {{ $template->frequency->label() }}
            </td>
            <td class="px-4 py-3 tabular-nums text-ink-soft">
                {{ $template->next_invoice_date->format('d M Y') }}
            </td>
            <td class="px-4 py-3">
                <span @class([
                    'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                    'bg-green-50 text-success' => $template->status === \App\Modules\Billing\Enums\RecurringInvoiceStatus::Active,
                    'bg-yellow-50 text-warning' => $template->status === \App\Modules\Billing\Enums\RecurringInvoiceStatus::Paused,
                    'bg-surface-alt text-ink-muted' => $template->status === \App\Modules\Billing\Enums\RecurringInvoiceStatus::Completed,
                ])>
                    {{ $template->status->label() }}
                </span>
            </td>
            <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    @can('update', $template)
                        <flux:button wire:click="edit('{{ $template->id }}')" size="sm" variant="ghost" icon="pencil" />
                    @endcan
                    @can('delete', $template)
                        <flux:button
                            wire:click="delete('{{ $template->id }}')"
                            wire:confirm="Delete this recurring invoice template? This cannot be undone."
                            size="sm" variant="ghost" icon="trash"
                            class="text-danger hover:text-danger"
                        />
                    @endcan
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="5" class="px-4 py-12 text-center">
                <p class="font-medium text-ink">No recurring invoice templates yet.</p>
                <p class="mt-1 text-sm text-ink-muted">Create a template to automate invoice generation.</p>
            </td>
        </tr>
    @endforelse

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

{{-- ===== Form Flyout ===== --}}
<flux:modal name="crud-form" flyout wire:model.self="showForm" class="w-[640px]">
    <form wire:submit="save" class="flex flex-col h-full">
        <div class="p-6 border-b border-line">
            <flux:heading size="lg" class="font-semibold">
                {{ $editingId ? 'Edit Recurring Invoice' : 'New Recurring Invoice' }}
            </flux:heading>
        </div>

        {{-- Tabs (show history tab only when editing) --}}
        @if($editingId)
        <div class="flex border-b border-line">
            <button
                type="button"
                wire:click="$set('activeTab', 'details')"
                @class([
                    'px-5 py-3 text-sm font-medium border-b-2 -mb-px transition-colors',
                    'border-primary text-primary' => $activeTab === 'details',
                    'border-transparent text-ink-muted hover:text-ink' => $activeTab !== 'details',
                ])
            >Details</button>
            <button
                type="button"
                wire:click="$set('activeTab', 'history')"
                @class([
                    'px-5 py-3 text-sm font-medium border-b-2 -mb-px transition-colors',
                    'border-primary text-primary' => $activeTab === 'history',
                    'border-transparent text-ink-muted hover:text-ink' => $activeTab !== 'history',
                ])
            >History ({{ $historyInvoices->count() }})</button>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto">

            {{-- Details Tab --}}
            @if($activeTab === 'details')
            <div class="p-6 space-y-4">

                <div class="grid grid-cols-2 gap-4">
                    <flux:field class="col-span-2">
                        <flux:label>Client <span class="text-danger">*</span></flux:label>
                        <flux:select wire:model="clientId">
                            <option value="">— Select client —</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}">{{ $client->business?->display_name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="clientId" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Frequency <span class="text-danger">*</span></flux:label>
                        <flux:select wire:model="frequency">
                            @foreach($frequencies as $freq)
                                <option value="{{ $freq->value }}">{{ $freq->label() }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="frequency" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Billing Period Day <span class="text-danger">*</span></flux:label>
                        <flux:input type="number" wire:model="billingPeriodDay" min="1" max="28" />
                        <flux:description>Day of month when the billing period starts (1–28)</flux:description>
                        <flux:error name="billingPeriodDay" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Start Date <span class="text-danger">*</span></flux:label>
                        <flux:input type="date" wire:model="startDate" />
                        <flux:error name="startDate" />
                    </flux:field>

                    <flux:field>
                        <flux:label>End Date</flux:label>
                        <flux:input type="date" wire:model="endDate" />
                        <flux:description>Leave blank for indefinite</flux:description>
                        <flux:error name="endDate" />
                    </flux:field>

                    <flux:field class="col-span-2">
                        <flux:label>Payment Terms</flux:label>
                        <flux:select wire:model="paymentTermId">
                            <option value="">— Use client default —</option>
                            @foreach($paymentTerms as $term)
                                <option value="{{ $term->id }}">{{ $term->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="paymentTermId" />
                    </flux:field>

                    <flux:field class="col-span-2">
                        <flux:label>Notes</flux:label>
                        <flux:textarea wire:model="notes" rows="2" placeholder="Internal notes…" />
                        <flux:error name="notes" />
                    </flux:field>
                </div>

                {{-- Lines section --}}
                <div class="pt-2">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-ink-muted uppercase tracking-wide">Line Items</p>
                        <flux:button type="button" wire:click="addFormLine" size="xs" variant="ghost" icon="plus">Add Line</flux:button>
                    </div>

                    @if(count($formLines) > 0)
                    <div class="border border-line rounded overflow-hidden">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-surface-alt border-b border-line">
                                    <th class="px-3 py-2 text-left text-ink-muted font-semibold uppercase tracking-wide">Description</th>
                                    <th class="px-3 py-2 text-left text-ink-muted font-semibold uppercase tracking-wide w-36">Account</th>
                                    <th class="px-3 py-2 text-right text-ink-muted font-semibold uppercase tracking-wide w-16">Qty</th>
                                    <th class="px-3 py-2 text-right text-ink-muted font-semibold uppercase tracking-wide w-24">Price</th>
                                    <th class="px-3 py-2 text-right text-ink-muted font-semibold uppercase tracking-wide w-16">Tax %</th>
                                    <th class="px-2 py-2 w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($formLines as $i => $line)
                                <tr class="border-b border-line last:border-b-0">
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="text"
                                            wire:model="formLines.{{ $i }}.description"
                                            placeholder="Description"
                                            class="w-full border border-line rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <select
                                            wire:model="formLines.{{ $i }}.account_id"
                                            class="w-full border border-line rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                                        >
                                            <option value="">—</option>
                                            @foreach($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="number"
                                            wire:model="formLines.{{ $i }}.quantity"
                                            step="0.0001"
                                            min="0"
                                            class="w-full border border-line rounded px-2 py-1 text-xs text-right focus:outline-none focus:ring-1 focus:ring-primary"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="number"
                                            wire:model="formLines.{{ $i }}.unit_price"
                                            step="0.01"
                                            min="0"
                                            placeholder="0.00"
                                            class="w-full border border-line rounded px-2 py-1 text-xs text-right focus:outline-none focus:ring-1 focus:ring-primary"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="number"
                                            wire:model="formLines.{{ $i }}.tax_rate"
                                            step="0.01"
                                            min="0"
                                            max="100"
                                            placeholder="15"
                                            class="w-full border border-line rounded px-2 py-1 text-xs text-right focus:outline-none focus:ring-1 focus:ring-primary"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5 text-center">
                                        <button
                                            type="button"
                                            wire:click="removeFormLine({{ $i }})"
                                            class="text-ink-muted hover:text-danger transition-colors"
                                        >
                                            <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                        </button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    <div class="text-center py-4 text-xs text-ink-muted border border-dashed border-line rounded">
                        No lines added. Click "Add Line" to add invoice lines.
                    </div>
                    @endif
                </div>

            </div>
            @endif

            {{-- History Tab --}}
            @if($activeTab === 'history')
            <div class="p-6">
                @if($historyInvoices->isEmpty())
                    <p class="text-sm text-ink-muted text-center py-8">No invoices generated from this template yet.</p>
                @else
                <div class="border border-line rounded overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-surface-alt border-b border-line">
                                <th class="px-4 py-3 text-left text-xs font-semibold text-ink-muted uppercase tracking-wide">Invoice #</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-ink-muted uppercase tracking-wide">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-ink-muted uppercase tracking-wide">Total</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-ink-muted uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($historyInvoices as $invoice)
                            <tr class="border-b border-line last:border-b-0">
                                <td class="px-4 py-3 font-medium text-ink">
                                    {{ $invoice->document_number ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-ink-soft tabular-nums">
                                    {{ $invoice->issue_date?->format('d M Y') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink">
                                    {{ $currencySymbol }}{{ number_format((float)$invoice->total, 2) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                        'bg-surface-alt text-ink-muted' => $invoice->status === 'draft',
                                        'bg-blue-50 text-blue-700' => $invoice->status === 'sent',
                                        'bg-red-50 text-danger' => $invoice->status === 'voided',
                                    ])>
                                        {{ ucfirst($invoice->status) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
            @endif

        </div>

        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
            <flux:button type="button" variant="ghost" wire:click="cancelForm">Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                {{ $editingId ? 'Save changes' : 'Create Template' }}
            </flux:button>
        </div>
    </form>
</flux:modal>

</div>
