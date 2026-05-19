<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * TransactionStatus
 *
 * Backed enum representing the closed set of transaction states.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
