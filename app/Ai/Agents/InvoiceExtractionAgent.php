<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxTokens(4096)]
class InvoiceExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * The full instruction/schema/data prompt is built per-call by LlmService
     * (via the invoice-extraction Blade view, unchanged) and passed as the
     * prompt text itself, so this only needs to identify the agent's role.
     */
    public function instructions(): Stringable|string
    {
        return 'You are Merlin\'s invoice extraction assistant. Follow the instructions given in the prompt exactly.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'supplier_name' => $schema->string(),
            'supplier_tax_number' => $schema->string(),
            'supplier_email' => $schema->string(),
            'supplier_phone' => $schema->string(),
            'invoice_number' => $schema->string(),
            'issue_date' => $schema->string(),
            'due_date' => $schema->string(),
            'currency' => $schema->string(),
            'subtotal' => $schema->number(),
            'tax_total' => $schema->number(),
            'total' => $schema->number(),
            'confidence' => $schema->number(),
            'already_paid' => $schema->boolean(),
            'warnings' => $schema->array()->items($schema->string()),
            'lines' => $schema->array()->items($schema->object([
                'description' => $schema->string(),
                'quantity' => $schema->number(),
                'unit_price' => $schema->number(),
                'line_total' => $schema->number(),
                'tax_rate' => $schema->number(),
                'suggested_account_code' => $schema->string(),
                'account_confidence' => $schema->number(),
                'account_reason' => $schema->string(),
            ])),
        ];
    }
}
