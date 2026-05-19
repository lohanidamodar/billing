<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * InvoiceStatus
 *
 * Backed enum representing the closed set of invoice states.
 *
 * Lifecycle: draft -> finalized -> paid | failed | voided
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Paid = 'paid';
    case Failed = 'failed';
    case Voided = 'voided';

    /**
     * Check if the invoice is in a finalized or later state (immutable).
     */
    public function isFinalized(): bool
    {
        return match ($this) {
            self::Finalized,
            self::Paid,
            self::Failed,
            self::Voided => true,
            default => false,
        };
    }
}
