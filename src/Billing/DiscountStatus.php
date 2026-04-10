<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * DiscountStatus
 *
 * Backed enum representing the closed set of discount states.
 */
enum DiscountStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Cancelled = 'cancelled';
}
