<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxTokens(8192)]
class BankStatementExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * The full instruction/schema/data prompt is built per-call by LlmService
     * (via the bank-statement-extraction Blade view, unchanged) and passed as
     * the prompt text itself, so this only needs to identify the agent's role.
     */
    public function instructions(): Stringable|string
    {
        return 'You are Merlin\'s bank statement extraction assistant. Follow the instructions given in the prompt exactly.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'bank_name' => $schema->string(),
            'account_name' => $schema->string(),
            'account_number_last4' => $schema->string(),
            'statement_number' => $schema->string(),
            'period_from' => $schema->string(),
            'period_to' => $schema->string(),
            'opening_balance' => $schema->number(),
            'closing_balance' => $schema->number(),
            'currency' => $schema->string(),
            'confidence' => $schema->number(),
            'warnings' => $schema->array()->items($schema->string()),
            'transactions' => $schema->array()->items($schema->object([
                'transaction_date' => $schema->string(),
                'description' => $schema->string(),
                'debit' => $schema->number(),
                'credit' => $schema->number(),
                'running_balance' => $schema->number(),
                'suggested_account_code' => $schema->string(),
                'account_confidence' => $schema->number(),
                'account_reason' => $schema->string(),
                'suggested_invoice_number' => $schema->string(),
                'invoice_match_confidence' => $schema->number(),
                'invoice_match_reason' => $schema->string(),
            ])),
        ];
    }
}
