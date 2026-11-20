<?php

namespace App\Livewire;

use App\Modules\Core\Models\Document;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Services\ExchangeRateService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class UnpaidInvoicesPanel extends Component
{
    private const LIST_LIMIT = 10;

    /**
     * Sales invoices that still owe money: the Unpaid tab's statuses, with a balance.
     * Zero-value invoices owe nothing and are not listed.
     *
     * @return Builder<Document>
     */
    private function unpaid(): Builder
    {
        return Document::salesInvoices()
            ->joinBalance()
            ->whereIn('document_balances.status', ['sent', 'partially_paid'])
            ->where('document_balances.balance_due', '>', 0);
    }

    public function render(): View
    {
        $canView = Auth::check() && Auth::user()->can('documents-view-any');

        return view('livewire.unpaid-invoices-panel', [
            'canView' => $canView,
            'total' => $canView ? (float) $this->unpaid()->sum('document_balances.balance_due') : 0.0,
            'count' => $canView ? $this->unpaid()->count() : 0,
            // Oldest due date first, so what is most overdue is at the top; no due date last.
            'rows' => $canView
                ? $this->unpaid()
                    ->with('party.business')
                    ->orderByRaw('documents.due_date IS NULL')
                    ->orderBy('documents.due_date')
                    ->orderBy('documents.document_number')
                    ->limit(self::LIST_LIMIT)
                    ->get()
                : collect(),
            'currencySymbol' => ExchangeRateService::currencySymbol(app(CurrencySettings::class)->base_currency),
        ]);
    }
}
