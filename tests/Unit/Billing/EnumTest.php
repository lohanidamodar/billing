<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\ChangeType;
use Utopia\Billing\CouponDuration;
use Utopia\Billing\CouponType;
use Utopia\Billing\DiscountStatus;
use Utopia\Billing\InvoiceStatus;
use Utopia\Billing\SubscriptionStatus;
use Utopia\Billing\TransactionStatus;
use Utopia\Billing\TransactionType;

class EnumTest extends TestCase
{
    public function test_subscription_status_is_accessible(): void
    {
        $this->assertTrue(SubscriptionStatus::Active->isAccessible());
        $this->assertTrue(SubscriptionStatus::Trialing->isAccessible());
        $this->assertTrue(SubscriptionStatus::PastDue->isAccessible());
        $this->assertTrue(SubscriptionStatus::Canceling->isAccessible());
        $this->assertFalse(SubscriptionStatus::Incomplete->isAccessible());
        $this->assertFalse(SubscriptionStatus::IncompleteExpired->isAccessible());
        $this->assertFalse(SubscriptionStatus::Canceled->isAccessible());
        $this->assertFalse(SubscriptionStatus::Suspended->isAccessible());
    }

    public function test_subscription_status_is_terminal(): void
    {
        $this->assertTrue(SubscriptionStatus::IncompleteExpired->isTerminal());
        $this->assertTrue(SubscriptionStatus::Canceled->isTerminal());
        $this->assertFalse(SubscriptionStatus::Active->isTerminal());
        $this->assertFalse(SubscriptionStatus::Trialing->isTerminal());
        $this->assertFalse(SubscriptionStatus::PastDue->isTerminal());
        $this->assertFalse(SubscriptionStatus::Canceling->isTerminal());
        $this->assertFalse(SubscriptionStatus::Incomplete->isTerminal());
        $this->assertFalse(SubscriptionStatus::Suspended->isTerminal());
    }

    public function test_invoice_status_is_finalized(): void
    {
        $this->assertTrue(InvoiceStatus::Finalized->isFinalized());
        $this->assertTrue(InvoiceStatus::Paid->isFinalized());
        $this->assertTrue(InvoiceStatus::Failed->isFinalized());
        $this->assertTrue(InvoiceStatus::Voided->isFinalized());
        $this->assertFalse(InvoiceStatus::Draft->isFinalized());
    }

    public function test_transaction_type_categories(): void
    {
        // Wallet types
        $this->assertTrue(TransactionType::WalletTopup->isWallet());
        $this->assertTrue(TransactionType::WalletDeduction->isWallet());
        $this->assertTrue(TransactionType::WalletRefund->isWallet());
        $this->assertFalse(TransactionType::GatewayCharge->isWallet());
        $this->assertFalse(TransactionType::CouponCredit->isWallet());

        // Gateway types
        $this->assertTrue(TransactionType::GatewayCharge->isGateway());
        $this->assertTrue(TransactionType::GatewayRefund->isGateway());
        $this->assertFalse(TransactionType::WalletTopup->isGateway());
        $this->assertFalse(TransactionType::CouponCredit->isGateway());

        // Credit types
        $this->assertTrue(TransactionType::CouponCredit->isCredit());
        $this->assertTrue(TransactionType::CreditExpiry->isCredit());
        $this->assertFalse(TransactionType::GatewayCharge->isCredit());
        $this->assertFalse(TransactionType::WalletTopup->isCredit());
    }

    public function test_enum_backed_values(): void
    {
        $this->assertEquals('active', SubscriptionStatus::Active->value);
        $this->assertEquals('past_due', SubscriptionStatus::PastDue->value);
        $this->assertEquals('draft', InvoiceStatus::Draft->value);
        $this->assertEquals('gateway_charge', TransactionType::GatewayCharge->value);
        $this->assertEquals('succeeded', TransactionStatus::Succeeded->value);
        $this->assertEquals('fixed', CouponType::Fixed->value);
        $this->assertEquals('percentage', CouponType::Percentage->value);
        $this->assertEquals('once', CouponDuration::Once->value);
        $this->assertEquals('repeating', CouponDuration::Repeating->value);
        $this->assertEquals('forever', CouponDuration::Forever->value);
        $this->assertEquals('upgrade', ChangeType::Upgrade->value);
        $this->assertEquals('downgrade', ChangeType::Downgrade->value);
        $this->assertEquals('active', DiscountStatus::Active->value);
        $this->assertEquals('exhausted', DiscountStatus::Exhausted->value);
        $this->assertEquals('cancelled', DiscountStatus::Cancelled->value);
    }
}
