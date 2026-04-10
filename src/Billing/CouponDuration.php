<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * CouponDuration
 *
 * Backed enum for coupon duration types.
 */
enum CouponDuration: string
{
    case Once = 'once';
    case Repeating = 'repeating';
    case Forever = 'forever';
}
