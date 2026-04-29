<?php

namespace Database\Factories\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Party;
use App\Modules\Purchasing\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'document_type' => 'purchase_invoice',
            'direction' => 'inbound',
            'party_id' => Party::factory(),
            'payable_account_id' => Account::factory(),
            'status' => 'received',
            'issue_date' => now()->subDays(rand(1, 30))->toDateString(),
            'due_date' => now()->subDays(rand(1, 30))->toDateString(),
            'currency' => 'ZAR',
            'exchange_rate' => 1.000000,
            'source' => 'manual',
        ];
    }

    public function purchaseInvoice(): static
    {
        return $this->state([
            'document_type' => 'purchase_invoice',
            'direction' => 'inbound',
        ]);
    }

    public function salesInvoice(): static
    {
        return $this->state([
            'document_type' => 'sales_invoice',
            'direction' => 'outbound',
        ]);
    }

    public function withNumber(string $number): static
    {
        return $this->state(['document_number' => $number]);
    }
}
