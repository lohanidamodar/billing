<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * BillingEvent
 *
 * Backed enum defining all event names for the billing listener system.
 * Follows the same on() pattern as utopia-php/database.
 */
enum BillingEvent: string
{
    // Subscription events
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionUpgraded = 'subscription.upgraded';
    case SubscriptionDowngraded = 'subscription.downgraded';
    case SubscriptionCanceled = 'subscription.canceled';
    case SubscriptionSuspended = 'subscription.suspended';
    case SubscriptionRenewed = 'subscription.renewed';
    case SubscriptionUpgradePending = 'subscription.upgrade_pending';
    case SubscriptionUpgradeFailed = 'subscription.upgrade_failed';
    case SubscriptionDowngradeScheduled = 'subscription.downgrade_scheduled';
    case SubscriptionBudgetWarning = 'subscription.budget_warning';
    case SubscriptionBudgetReached = 'subscription.budget_reached';

    // Invoice events
    case InvoiceFinalized = 'invoice.finalized';
    case InvoicePaid = 'invoice.paid';
    case InvoiceFailed = 'invoice.failed';
    case InvoiceVoided = 'invoice.voided';

    // Discount events
    case DiscountApplied = 'discount.applied';
    case DiscountExhausted = 'discount.exhausted';
    case DiscountCancelled = 'discount.cancelled';

    // Wallet events
    case WalletFunded = 'wallet.funded';
    case WalletDeducted = 'wallet.deducted';

    // Coupon events
    case CouponRedeemed = 'coupon.redeemed';

    // Payment events
    case PaymentFailed = 'payment.failed';

    // Transaction events
    case TransactionCreated = 'transaction.created';
}
