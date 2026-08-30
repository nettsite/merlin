<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The model responded successfully but its output can't be used — invalid
 * JSON, or (via reconciliation checks upstream) totals that don't add up.
 * Narrower than a bare RuntimeException so the tier-fallback catch in
 * LlmService only escalates to the stronger model for this reason, not for
 * every RuntimeException — including LlmCreditExhaustedException, which must
 * propagate instead of triggering a second billable call.
 */
class LlmUnusableOutputException extends RuntimeException {}
