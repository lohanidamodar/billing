<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * ChangeType
 *
 * Backed enum for pending subscription plan change types.
 */
enum ChangeType: string
{
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
}
