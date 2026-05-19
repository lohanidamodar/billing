<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * SubscriptionStatus
 *
 * Backed enum representing the closed set of subscription states.
 *
 * State machine:
 *   incomplete -> active (payment succeeds) | incomplete_expired (timeout)
 *   trialing -> active (trial ends + payment succeeds)
 *   active -> past_due (renewal fails) | canceling (cancel at period end)
 *   past_due -> active (retry succeeds) | suspended (max retries exhausted)
 *   canceling -> canceled (period ends)
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceling = 'canceling';
    case Canceled = 'canceled';
    case Suspended = 'suspended';

    /**
     * Check if this status grants access to the service.
     */
    public function isAccessible(): bool
    {
        return match ($this) {
            self::Active,
            self::Trialing,
            self::PastDue,
            self::Canceling => true,
            default => false,
        };
    }

    /**
     * Check if this is a terminal (final) status.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::IncompleteExpired,
            self::Canceled => true,
            default => false,
        };
    }
}
