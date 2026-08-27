<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Plain-text (not JSON) agent — generates bank-template layout hint bullets.
 */
#[MaxTokens(4096)]
class BankTemplateHintsAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are Merlin\'s bank template hints assistant. Follow the instructions given in the prompt exactly.';
    }
}
