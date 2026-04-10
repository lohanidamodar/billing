<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * CouponType
 *
 * Backed enum for coupon/discount value types.
 */
enum CouponType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
