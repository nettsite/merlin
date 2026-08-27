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
class PaymentNotificationExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * The full instruction/schema/data prompt is built per-call by LlmService
     * (via the payment-notification-extraction Blade view, unchanged) and
     * passed as the prompt text itself, so this only needs the agent's role.
     */
    public function instructions(): Stringable|string
    {
        return 'You are Merlin\'s payment notification extraction assistant. Follow the instructions given in the prompt exactly.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'payment_date' => $schema->string(),
            'paid_amount' => $schema->number(),
            'paid_currency' => $schema->string(),
            'reference_text' => $schema->string(),
            'payee_name' => $schema->string(),
            'method' => $schema->string(),
            'confirmed' => $schema->boolean(),
            'confidence' => $schema->number(),
            'warnings' => $schema->array()->items($schema->string()),
        ];
    }
}
