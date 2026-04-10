<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use DateTime;
use PHPUnit\Framework\TestCase;
use Utopia\Billing\Billing;
use Utopia\Billing\BillingEvent;
use Utopia\Billing\ChangeType;
use Utopia\Billing\Coupon;
use Utopia\Billing\CouponDuration;
use Utopia\Billing\CouponType;
use Utopia\Billing\Discount;
use Utopia\Billing\DiscountStatus;
use Utopia\Billing\Exception;
use Utopia\Billing\Invoice;
use Utopia\Billing\InvoiceStatus;
use Utopia\Billing\Period;
use Utopia\Billing\Subscription;
use Utopia\Billing\SubscriptionStatus;
use Utopia\Billing\TransactionType;

class BillingTest extends TestCase
{
    private Billing $billing;

    protected function setUp(): void
    {
        $this->billing = new Billing(new InMemoryAdapter());
        $this->billing->setup();
    }

    // =========================================================================
    // Subscriptions
    // =========================================================================

    public function testCreateSubscription(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');

        $this->assertNotEmpty($sub->getId());
        $this->assertEquals('entity-1', $sub->getEntityId());
        $this->assertEquals('plan-pro', $sub->getPlanId());
        $this->assertEquals(SubscriptionStatus::Incomplete, $sub->getStatus());
        $this->assertNotEmpty($sub->getCurrentPeriodStart());
        $this->assertNotEmpty($sub->getCurrentPeriodEnd());
        $this->assertNull($sub->getTrialStart());
        $this->assertNull($sub->getTrialEnd());
        $this->assertFalse($sub->getCancelAtPeriodEnd());
        $this->assertEquals(0.0, $sub->getBudgetUsed());
    }

    public function testCreateSubscriptionWithTrial(): void
    {
        $trialEnd = (new DateTime())->modify('+14 days');
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro', $trialEnd);

        $this->assertEquals(SubscriptionStatus::Trialing, $sub->getStatus());
        $this->assertNotNull($sub->getTrialStart());
        $this->assertNotNull($sub->getTrialEnd());
        $this->assertTrue($sub->isAccessible());
    }

    public function testGetSubscription(): void
    {
        $created = $this->billing->createSubscription('entity-1', 'plan-pro');
        $fetched = $this->billing->getSubscription($created->getId());

        $this->assertEquals($created->getId(), $fetched->getId());
        $this->assertEquals('plan-pro', $fetched->getPlanId());
    }

    public function testGetSubscriptionNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Subscription not found');

        $this->billing->getSubscription('nonexistent-id');
    }

    public function testGetActiveSubscription(): void
    {
        // No subscription yet
        $this->assertNull($this->billing->getActiveSubscription('entity-1'));

        // Create and activate
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $active = $this->billing->getActiveSubscription('entity-1');
        $this->assertNotNull($active);
        $this->assertEquals($sub->getId(), $active->getId());
    }

    public function testCancelSubscriptionAtPeriodEnd(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $cancelled = $this->billing->cancelSubscription($sub->getId(), true);

        $this->assertEquals(SubscriptionStatus::Canceling, $cancelled->getStatus());
        $this->assertTrue($cancelled->getCancelAtPeriodEnd());
        $this->assertTrue($cancelled->isAccessible());
    }

    public function testCancelSubscriptionImmediately(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $cancelled = $this->billing->cancelSubscription($sub->getId(), false);

        $this->assertEquals(SubscriptionStatus::Canceled, $cancelled->getStatus());
        $this->assertFalse($cancelled->isAccessible());
        $this->assertTrue($cancelled->isTerminal());
    }

    public function testCannotCancelTerminatedSubscription(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->cancelSubscription($sub->getId(), false);

        $this->expectException(Exception::class);
        $this->billing->cancelSubscription($sub->getId(), false);
    }

    public function testRenewSubscription(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $renewed = $this->billing->renewSubscription($sub->getId());

        $this->assertEquals(SubscriptionStatus::Active, $renewed->getStatus());
        $this->assertNotEmpty($renewed->getCurrentPeriodStart());
        $this->assertNotEmpty($renewed->getCurrentPeriodEnd());
        $this->assertEquals(0.0, $renewed->getBudgetUsed());
        $this->assertFalse($renewed->getBudgetLimitReached());
    }

    public function testRenewCancelingSubscription(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->cancelSubscription($sub->getId(), true);

        $result = $this->billing->renewSubscription($sub->getId());

        $this->assertEquals(SubscriptionStatus::Canceled, $result->getStatus());
        $this->assertTrue($result->isTerminal());
    }

    // =========================================================================
    // Upgrades & Downgrades
    // =========================================================================

    public function testRequestUpgrade(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-basic');
        $this->billing->recordPaymentSuccess($sub->getId());

        $upgraded = $this->billing->requestUpgrade($sub->getId(), 'plan-pro');

        $this->assertEquals('plan-basic', $upgraded->getPlanId()); // Still old plan
        $this->assertEquals('plan-pro', $upgraded->getPendingPlanId());
        $this->assertEquals(ChangeType::Upgrade, $upgraded->getPendingChangeType());
        $this->assertNotNull($upgraded->getPendingChangedAt());
        $this->assertNotNull($upgraded->getPendingExpiresAt());
        $this->assertTrue($upgraded->hasPendingChange());
    }

    public function testFinalizeUpgrade(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-basic');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->requestUpgrade($sub->getId(), 'plan-pro');

        $finalized = $this->billing->finalizeUpgrade($sub->getId());

        $this->assertEquals('plan-pro', $finalized->getPlanId());
        $this->assertNull($finalized->getPendingPlanId());
        $this->assertNull($finalized->getPendingChangeType());
        $this->assertFalse($finalized->hasPendingChange());
    }

    public function testCancelUpgrade(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-basic');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->requestUpgrade($sub->getId(), 'plan-pro');

        $cancelled = $this->billing->cancelUpgrade($sub->getId());

        $this->assertEquals('plan-basic', $cancelled->getPlanId());
        $this->assertNull($cancelled->getPendingPlanId());
        $this->assertFalse($cancelled->hasPendingChange());
    }

    public function testCannotUpgradeWithPendingChange(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-basic');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->requestUpgrade($sub->getId(), 'plan-pro');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('pending plan change');
        $this->billing->requestUpgrade($sub->getId(), 'plan-enterprise');
    }

    public function testRequestDowngrade(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $downgraded = $this->billing->requestDowngrade($sub->getId(), 'plan-basic');

        $this->assertEquals('plan-pro', $downgraded->getPlanId()); // Still old plan
        $this->assertEquals('plan-basic', $downgraded->getPendingPlanId());
        $this->assertEquals(ChangeType::Downgrade, $downgraded->getPendingChangeType());
    }

    public function testApplyPendingDowngrade(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->requestDowngrade($sub->getId(), 'plan-basic');

        $applied = $this->billing->applyPendingDowngrade($sub->getId());

        $this->assertEquals('plan-basic', $applied->getPlanId());
        $this->assertNull($applied->getPendingPlanId());
    }

    public function testCancelDowngrade(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->requestDowngrade($sub->getId(), 'plan-basic');

        $cancelled = $this->billing->cancelDowngrade($sub->getId());

        $this->assertEquals('plan-pro', $cancelled->getPlanId());
        $this->assertNull($cancelled->getPendingPlanId());
    }

    public function testRenewAppliesPendingDowngrade(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->requestDowngrade($sub->getId(), 'plan-basic');

        $renewed = $this->billing->renewSubscription($sub->getId());

        $this->assertEquals('plan-basic', $renewed->getPlanId());
        $this->assertNull($renewed->getPendingPlanId());
    }

    // =========================================================================
    // Budget
    // =========================================================================

    public function testSetBudget(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');

        $updated = $this->billing->setBudget($sub->getId(), 100.0);

        $this->assertEquals(100.0, $updated->getBudget());
        $this->assertFalse($updated->getBudgetLimitReached());
    }

    public function testUpdateBudgetUsed(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->setBudget($sub->getId(), 100.0);

        $updated = $this->billing->updateBudgetUsed($sub->getId(), 50.0);
        $this->assertEquals(50.0, $updated->getBudgetUsed());
        $this->assertFalse($updated->getBudgetLimitReached());

        $reached = $this->billing->updateBudgetUsed($sub->getId(), 100.0);
        $this->assertTrue($reached->getBudgetLimitReached());
    }

    public function testBudgetReachedEvent(): void
    {
        $eventFired = false;
        $this->billing->on(BillingEvent::SubscriptionBudgetReached, 'test', function () use (&$eventFired) {
            $eventFired = true;
        });

        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->setBudget($sub->getId(), 100.0);
        $this->billing->updateBudgetUsed($sub->getId(), 100.0);

        $this->assertTrue($eventFired);
    }

    // =========================================================================
    // Dunning
    // =========================================================================

    public function testRecordPaymentFailure(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $failed = $this->billing->recordPaymentFailure($sub->getId());

        $this->assertEquals(SubscriptionStatus::PastDue, $failed->getStatus());
        $this->assertEquals(1, $failed->getFailedPaymentAttempts());
        $this->assertNotNull($failed->getLastFailedAt());
    }

    public function testRecordPaymentSuccess(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');

        $success = $this->billing->recordPaymentSuccess($sub->getId());

        $this->assertEquals(SubscriptionStatus::Active, $success->getStatus());
        $this->assertEquals(0, $success->getFailedPaymentAttempts());
        $this->assertNull($success->getLastFailedAt());
    }

    public function testSuspendSubscription(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->recordPaymentFailure($sub->getId());
        $this->billing->recordPaymentFailure($sub->getId());
        $this->billing->recordPaymentFailure($sub->getId());

        $suspended = $this->billing->suspendSubscription($sub->getId());

        $this->assertEquals(SubscriptionStatus::Suspended, $suspended->getStatus());
        $this->assertFalse($suspended->isAccessible());
    }

    // =========================================================================
    // Invoices
    // =========================================================================

    public function testCreateInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription');

        $this->assertNotEmpty($invoice->getId());
        $this->assertEquals('entity-1', $invoice->getEntityId());
        $this->assertEquals('subscription', $invoice->getType());
        $this->assertEquals(InvoiceStatus::Draft, $invoice->getStatus());
        $this->assertEquals('USD', $invoice->getCurrency());
        $this->assertEquals(0.0, $invoice->getTotal());
    }

    public function testCreateInvoiceWithItems(): void
    {
        $items = [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
            ['type' => 'usage', 'description' => 'Bandwidth', 'amount' => 3.40, 'resource' => 'bandwidth'],
        ];

        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, $items);

        $this->assertCount(2, $invoice->getItems());
    }

    public function testAddInvoiceItem(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription');

        $updated = $this->billing->addInvoiceItem($invoice->getId(), [
            'type' => 'plan',
            'description' => 'Pro Plan',
            'amount' => 15.00,
        ]);

        $this->assertCount(1, $updated->getItems());
    }

    public function testCannotAddItemToFinalizedInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
        ]);
        $this->billing->finalizeInvoice($invoice->getId());

        $this->expectException(Exception::class);
        $this->billing->addInvoiceItem($invoice->getId(), [
            'type' => 'addon',
            'description' => 'Extra',
            'amount' => 5.00,
        ]);
    }

    public function testFinalizeInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
            ['type' => 'usage', 'description' => 'Bandwidth', 'amount' => 3.40, 'resource' => 'bandwidth'],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        $this->assertEquals(InvoiceStatus::Finalized, $finalized->getStatus());
        $this->assertEquals(18.40, $finalized->getSubtotal());
        $this->assertEquals(18.40, $finalized->getTotal());
        $this->assertEquals(0.0, $finalized->getDiscountTotal());
        $this->assertEquals(0.0, $finalized->getTaxTotal());
        $this->assertNotEmpty($finalized->getNumber());
        $this->assertTrue($finalized->isFinalized());
    }

    public function testFinalizeInvoiceWithTax(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 100.00],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId(), [
            'taxRate' => 17.0,
            'taxDescription' => 'VAT',
        ]);

        $this->assertEquals(100.0, $finalized->getSubtotal());
        $this->assertEquals(17.0, $finalized->getTaxTotal());
        $this->assertEquals(117.0, $finalized->getTotal());

        // Check tax line item was added
        $taxItems = $finalized->getItemsByType(Invoice::ITEM_TYPE_TAX);
        $this->assertCount(1, $taxItems);
        $this->assertEquals(17.0, $taxItems[0]['amount']);
        $this->assertStringContainsString('17.0%', (string) $taxItems[0]['description']);
    }

    public function testFinalizeInvoiceWithDiscounts(): void
    {
        // Create subscription and apply a percentage discount
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $coupon = $this->billing->createCoupon('SAVE10', 'percentage', 10.0, 'forever');
        $this->billing->applyDiscount('SAVE10', $sub->getId(), 'entity-1');

        $invoice = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 100.00],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        $this->assertEquals(100.0, $finalized->getSubtotal());
        $this->assertEquals(10.0, $finalized->getDiscountTotal());
        $this->assertEquals(90.0, $finalized->getTotal());

        $discountItems = $finalized->getItemsByType(Invoice::ITEM_TYPE_DISCOUNT);
        $this->assertCount(1, $discountItems);
        $this->assertEquals(-10.0, $discountItems[0]['amount']);
    }

    public function testFinalizeInvoiceWithLineLevelDiscount(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $coupon = $this->billing->createCoupon('BW_HALF', 'percentage', 50.0, 'forever', null, [
            'scope' => ['resources' => ['bandwidth']],
        ]);
        $this->billing->applyDiscount('BW_HALF', $sub->getId(), 'entity-1');

        $invoice = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
            ['type' => 'usage', 'description' => 'Bandwidth', 'amount' => 10.00, 'resource' => 'bandwidth'],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        // Only bandwidth should be discounted (50% of 10.00 = 5.00)
        $this->assertEquals(25.0, $finalized->getSubtotal());
        $this->assertEquals(5.0, $finalized->getDiscountTotal());
        $this->assertEquals(20.0, $finalized->getTotal());
    }

    public function testFinalizeInvoiceWithDiscountAndTax(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $coupon = $this->billing->createCoupon('SAVE10', 'percentage', 10.0, 'once');
        $this->billing->applyDiscount('SAVE10', $sub->getId(), 'entity-1');

        $invoice = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 100.00],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId(), [
            'taxRate' => 10.0,
        ]);

        // 100 - 10 discount = 90 taxable, 10% tax = 9
        $this->assertEquals(100.0, $finalized->getSubtotal());
        $this->assertEquals(10.0, $finalized->getDiscountTotal());
        $this->assertEquals(9.0, $finalized->getTaxTotal());
        $this->assertEquals(99.0, $finalized->getTotal()); // 100 - 10 + 9
    }

    public function testCannotFinalizeTwice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
        ]);
        $this->billing->finalizeInvoice($invoice->getId());

        $this->expectException(Exception::class);
        $this->billing->finalizeInvoice($invoice->getId());
    }

    public function testMarkInvoicePaid(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
        ]);
        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        $paid = $this->billing->markInvoicePaid($invoice->getId(), 'pay-123');

        $this->assertEquals(InvoiceStatus::Paid, $paid->getStatus());
        $this->assertNotNull($paid->getPaidAt());
    }

    public function testMarkInvoiceFailed(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
        ]);
        $this->billing->finalizeInvoice($invoice->getId());

        $failed = $this->billing->markInvoiceFailed($invoice->getId());

        $this->assertEquals(InvoiceStatus::Failed, $failed->getStatus());
    }

    public function testVoidInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
        ]);
        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        $voided = $this->billing->voidInvoice($invoice->getId());

        $this->assertEquals(InvoiceStatus::Voided, $voided->getStatus());
    }

    public function testCannotVoidPaidInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
        ]);
        $this->billing->finalizeInvoice($invoice->getId());
        $this->billing->markInvoicePaid($invoice->getId(), 'pay-123');

        $this->expectException(Exception::class);
        $this->billing->voidInvoice($invoice->getId());
    }

    public function testListInvoices(): void
    {
        $this->billing->createInvoice('entity-1', 'subscription');
        $this->billing->createInvoice('entity-1', 'wallet_topup');
        $this->billing->createInvoice('entity-2', 'subscription');

        $invoices = $this->billing->listInvoices('entity-1');
        $this->assertCount(2, $invoices);
        $this->assertInstanceOf(Invoice::class, $invoices[0]);
    }

    public function testCreateCreditNote(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 15.00],
        ]);
        $this->billing->finalizeInvoice($invoice->getId());
        $this->billing->markInvoicePaid($invoice->getId(), 'pay-123');

        $creditNote = $this->billing->createCreditNote($invoice->getId(), [
            ['type' => 'refund', 'description' => 'Refund Pro Plan', 'amount' => -15.00],
        ]);

        $this->assertEquals('credit_note', $creditNote->getType());
        $this->assertEquals($invoice->getId(), $creditNote->getReferenceInvoiceId());
        $this->assertTrue($creditNote->isCreditNote());
        $this->assertEquals('entity-1', $creditNote->getEntityId());
    }

    public function testInvoiceNumberGeneration(): void
    {
        $invoice1 = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan A', 'amount' => 10.00],
        ]);
        $finalized1 = $this->billing->finalizeInvoice($invoice1->getId());

        $invoice2 = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan B', 'amount' => 20.00],
        ]);
        $finalized2 = $this->billing->finalizeInvoice($invoice2->getId());

        $year = (new DateTime())->format('Y');
        $this->assertEquals("INV-{$year}-00001", $finalized1->getNumber());
        $this->assertEquals("INV-{$year}-00002", $finalized2->getNumber());
    }

    // =========================================================================
    // Coupons
    // =========================================================================

    public function testCreateCoupon(): void
    {
        $coupon = $this->billing->createCoupon('SUMMER20', 'percentage', 20.0, 'once');

        $this->assertNotEmpty($coupon->getId());
        $this->assertEquals('SUMMER20', $coupon->getCode());
        $this->assertEquals(CouponType::Percentage, $coupon->getType());
        $this->assertEquals(20.0, $coupon->getValue());
        $this->assertEquals(CouponDuration::Once, $coupon->getDuration());
        $this->assertTrue($coupon->isActive());
        $this->assertTrue($coupon->isRedeemable());
        $this->assertEquals(0, $coupon->getTimesRedeemed());
    }

    public function testCreateFixedCoupon(): void
    {
        $coupon = $this->billing->createCoupon('FLAT50', 'fixed', 50.0, 'once', null, [
            'currency' => 'USD',
            'maxRedemptions' => 100,
        ]);

        $this->assertTrue($coupon->isFixed());
        $this->assertFalse($coupon->isPercentage());
        $this->assertEquals('USD', $coupon->getCurrency());
        $this->assertEquals(100, $coupon->getMaxRedemptions());
    }

    public function testGetCoupon(): void
    {
        $this->billing->createCoupon('TEST10', 'percentage', 10.0, 'once');

        $fetched = $this->billing->getCoupon('TEST10');
        $this->assertEquals('TEST10', $fetched->getCode());
    }

    public function testGetCouponNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->billing->getCoupon('NONEXISTENT');
    }

    public function testDeactivateCoupon(): void
    {
        $this->billing->createCoupon('DEACTIVATE', 'percentage', 10.0, 'once');

        $deactivated = $this->billing->deactivateCoupon('DEACTIVATE');

        $this->assertFalse($deactivated->isActive());
        $this->assertFalse($deactivated->isRedeemable());
    }

    // =========================================================================
    // Discounts
    // =========================================================================

    public function testApplyDiscount(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->createCoupon('DISC10', 'percentage', 10.0, 'repeating', 3);

        $discount = $this->billing->applyDiscount('DISC10', $sub->getId(), 'entity-1');

        $this->assertNotEmpty($discount->getId());
        $this->assertEquals($sub->getId(), $discount->getSubscriptionId());
        $this->assertEquals('entity-1', $discount->getEntityId());
        $this->assertEquals(CouponType::Percentage, $discount->getType());
        $this->assertEquals(10.0, $discount->getValue());
        $this->assertEquals(CouponDuration::Repeating, $discount->getDuration());
        $this->assertEquals(3, $discount->getCyclesTotal());
        $this->assertEquals(3, $discount->getCyclesRemaining());
        $this->assertEquals(DiscountStatus::Active, $discount->getStatus());
        $this->assertTrue($discount->isActive());
    }

    public function testApplyDiscountIncrementsCouponRedemptions(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->createCoupon('REDEEM', 'percentage', 10.0, 'once');

        $this->billing->applyDiscount('REDEEM', $sub->getId(), 'entity-1');

        $coupon = $this->billing->getCoupon('REDEEM');
        $this->assertEquals(1, $coupon->getTimesRedeemed());
    }

    public function testCannotApplyDeactivatedCoupon(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->createCoupon('DEAD', 'percentage', 10.0, 'once');
        $this->billing->deactivateCoupon('DEAD');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not redeemable');
        $this->billing->applyDiscount('DEAD', $sub->getId(), 'entity-1');
    }

    public function testListActiveDiscounts(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->createCoupon('A', 'percentage', 5.0, 'forever');
        $this->billing->createCoupon('B', 'percentage', 10.0, 'forever');

        $this->billing->applyDiscount('A', $sub->getId(), 'entity-1');
        $this->billing->applyDiscount('B', $sub->getId(), 'entity-1');

        $discounts = $this->billing->listActiveDiscounts($sub->getId());
        $this->assertCount(2, $discounts);
        $this->assertInstanceOf(Discount::class, $discounts[0]);
    }

    public function testCancelDiscount(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->createCoupon('CANCEL', 'percentage', 10.0, 'forever');
        $discount = $this->billing->applyDiscount('CANCEL', $sub->getId(), 'entity-1');

        $cancelled = $this->billing->cancelDiscount($discount->getId());

        $this->assertEquals(DiscountStatus::Cancelled, $cancelled->getStatus());
        $this->assertTrue($cancelled->isCancelled());
        $this->assertNotNull($cancelled->getCancelledAt());
    }

    public function testDiscountExhaustion(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->createCoupon('ONCE', 'percentage', 10.0, 'once');
        $this->billing->applyDiscount('ONCE', $sub->getId(), 'entity-1');

        // Finalize an invoice — should exhaust the discount
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 100.00],
        ]);
        $this->billing->finalizeInvoice($invoice->getId());

        // Discount should be exhausted
        $discounts = $this->billing->listActiveDiscounts($sub->getId());
        $this->assertCount(0, $discounts);
    }

    // =========================================================================
    // Wallet
    // =========================================================================

    public function testGetOrCreateWallet(): void
    {
        $wallet = $this->billing->getOrCreateWallet('entity-1');

        $this->assertNotEmpty($wallet->getId());
        $this->assertEquals('entity-1', $wallet->getEntityId());
        $this->assertEquals(0.0, $wallet->getBalance());
        $this->assertEquals('USD', $wallet->getCurrency());

        // Second call returns same wallet
        $same = $this->billing->getOrCreateWallet('entity-1');
        $this->assertEquals($wallet->getId(), $same->getId());
    }

    public function testGetWalletBalance(): void
    {
        $this->assertEquals(0.0, $this->billing->getWalletBalance('entity-1'));

        $invoice = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $this->billing->addFunds('entity-1', $invoice->getId(), 50.0);

        $this->assertEquals(50.0, $this->billing->getWalletBalance('entity-1'));
    }

    public function testAddFunds(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $transaction = $this->billing->addFunds('entity-1', $invoice->getId(), 100.0);

        $this->assertEquals(TransactionType::WalletTopup, $transaction->getType());
        $this->assertEquals(100.0, $transaction->getAmount());
        $this->assertTrue($transaction->isSucceeded());
        $this->assertNotNull($transaction->getWalletId());

        $this->assertEquals(100.0, $this->billing->getWalletBalance('entity-1'));
    }

    public function testAddFundsRejectsNonPositive(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'wallet_topup');

        $this->expectException(Exception::class);
        $this->billing->addFunds('entity-1', $invoice->getId(), 0.0);
    }

    public function testDeductFunds(): void
    {
        $topupInvoice = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $this->billing->addFunds('entity-1', $topupInvoice->getId(), 100.0);

        $payInvoice = $this->billing->createInvoice('entity-1', 'subscription');
        $transaction = $this->billing->deductFunds('entity-1', $payInvoice->getId(), 30.0);

        $this->assertEquals(TransactionType::WalletDeduction, $transaction->getType());
        $this->assertEquals(30.0, $transaction->getAmount());
        $this->assertEquals(70.0, $this->billing->getWalletBalance('entity-1'));
    }

    public function testDeductFundsInsufficientBalance(): void
    {
        $topupInvoice = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $this->billing->addFunds('entity-1', $topupInvoice->getId(), 10.0);

        $payInvoice = $this->billing->createInvoice('entity-1', 'subscription');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Insufficient wallet balance');
        $this->billing->deductFunds('entity-1', $payInvoice->getId(), 50.0);
    }

    public function testRefundToWallet(): void
    {
        $topupInvoice = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $this->billing->addFunds('entity-1', $topupInvoice->getId(), 100.0);

        $payInvoice = $this->billing->createInvoice('entity-1', 'subscription');
        $this->billing->deductFunds('entity-1', $payInvoice->getId(), 50.0);

        $refund = $this->billing->refundToWallet('entity-1', $payInvoice->getId(), 25.0);

        $this->assertEquals(TransactionType::WalletRefund, $refund->getType());
        $this->assertEquals(75.0, $this->billing->getWalletBalance('entity-1'));
    }

    // =========================================================================
    // Transactions
    // =========================================================================

    public function testCreateTransaction(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription');
        $transaction = $this->billing->createTransaction(
            'entity-1',
            $invoice->getId(),
            TransactionType::GatewayCharge->value,
            99.99,
            null,
            'pi_stripe_123',
        );

        $this->assertNotEmpty($transaction->getId());
        $this->assertEquals('entity-1', $transaction->getEntityId());
        $this->assertEquals($invoice->getId(), $transaction->getInvoiceId());
        $this->assertEquals(TransactionType::GatewayCharge, $transaction->getType());
        $this->assertEquals(99.99, $transaction->getAmount());
        $this->assertEquals('pi_stripe_123', $transaction->getProviderPaymentId());
        $this->assertTrue($transaction->isGatewayTransaction());
    }

    public function testListTransactions(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription');
        $this->billing->createTransaction('entity-1', $invoice->getId(), TransactionType::GatewayCharge->value, 50.0);
        $this->billing->createTransaction('entity-1', $invoice->getId(), TransactionType::GatewayCharge->value, 25.0);
        $this->billing->createTransaction('entity-2', $invoice->getId(), TransactionType::GatewayCharge->value, 10.0);

        $transactions = $this->billing->listTransactions('entity-1');
        $this->assertCount(2, $transactions);
    }

    public function testListInvoiceTransactions(): void
    {
        $invoice1 = $this->billing->createInvoice('entity-1', 'subscription');
        $invoice2 = $this->billing->createInvoice('entity-1', 'wallet_topup');

        $this->billing->createTransaction('entity-1', $invoice1->getId(), TransactionType::GatewayCharge->value, 50.0);
        $this->billing->createTransaction('entity-1', $invoice2->getId(), TransactionType::WalletTopup->value, 25.0);

        $transactions = $this->billing->listInvoiceTransactions($invoice1->getId());
        $this->assertCount(1, $transactions);
        $this->assertEquals(50.0, $transactions[0]->getAmount());
    }

    // =========================================================================
    // Events
    // =========================================================================

    public function testEventSystem(): void
    {
        $events = [];

        $this->billing->on(BillingEvent::SubscriptionCreated, 'test', function ($sub) use (&$events) {
            $events[] = 'subscription.created';
        });
        $this->billing->on(BillingEvent::InvoiceFinalized, 'test', function ($inv) use (&$events) {
            $events[] = 'invoice.finalized';
        });
        $this->billing->on(BillingEvent::InvoicePaid, 'test', function ($inv) use (&$events) {
            $events[] = 'invoice.paid';
        });

        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 10.0],
        ]);
        $this->billing->finalizeInvoice($invoice->getId());
        $this->billing->markInvoicePaid($invoice->getId(), 'pay-1');

        $this->assertEquals(['subscription.created', 'invoice.finalized', 'invoice.paid'], $events);
    }

    public function testMultipleListenersPerEvent(): void
    {
        $count = 0;

        $this->billing->on(BillingEvent::SubscriptionCreated, 'listener-1', function () use (&$count) {
            $count++;
        });
        $this->billing->on(BillingEvent::SubscriptionCreated, 'listener-2', function () use (&$count) {
            $count++;
        });

        $this->billing->createSubscription('entity-1', 'plan-pro');

        $this->assertEquals(2, $count);
    }

    // =========================================================================
    // Period & Proration
    // =========================================================================

    public function testGetCurrentPeriod(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');

        $period = $this->billing->getCurrentPeriod($sub->getId());

        $this->assertInstanceOf(Period::class, $period);
        $this->assertTrue($period->getDays() > 0);
        $this->assertTrue($period->contains(new DateTime()));
    }

    public function testCalculateProration(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');

        $prorated = $this->billing->calculateProration($sub->getId(), 'plan-enterprise', 30.0);

        // Should be some fraction of 30.0
        $this->assertGreaterThan(0.0, $prorated);
        $this->assertLessThanOrEqual(30.0, $prorated);
    }

    // =========================================================================
    // Coupon Redeemability Edge Cases
    // =========================================================================

    public function testExpiredCouponNotRedeemable(): void
    {
        $past = (new DateTime())->modify('-1 day')->format('Y-m-d\TH:i:s.000+00:00');
        $coupon = $this->billing->createCoupon('EXPIRED', 'percentage', 10.0, 'once', null, [
            'expiresAt' => $past,
        ]);

        $this->assertFalse($coupon->isRedeemable());
    }

    public function testMaxRedemptionsReachedCouponNotRedeemable(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->createCoupon('LIMITED', 'percentage', 10.0, 'once', null, [
            'maxRedemptions' => 1,
        ]);

        $this->billing->applyDiscount('LIMITED', $sub->getId(), 'entity-1');

        $coupon = $this->billing->getCoupon('LIMITED');
        $this->assertFalse($coupon->isRedeemable());
    }

    public function testCannotApplyExpiredCoupon(): void
    {
        $past = (new DateTime())->modify('-1 day')->format('Y-m-d\TH:i:s.000+00:00');
        $this->billing->createCoupon('OLD', 'percentage', 10.0, 'once', null, [
            'expiresAt' => $past,
        ]);

        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not redeemable');
        $this->billing->applyDiscount('OLD', $sub->getId(), 'entity-1');
    }

    public function testCannotApplyMaxedOutCoupon(): void
    {
        $this->billing->createCoupon('ONEUSE', 'percentage', 10.0, 'once', null, [
            'maxRedemptions' => 1,
        ]);

        $sub1 = $this->billing->createSubscription('entity-1', 'plan-pro');
        $sub2 = $this->billing->createSubscription('entity-2', 'plan-pro');

        $this->billing->applyDiscount('ONEUSE', $sub1->getId(), 'entity-1');

        $this->expectException(Exception::class);
        $this->billing->applyDiscount('ONEUSE', $sub2->getId(), 'entity-2');
    }

    // =========================================================================
    // Subscription State Machine Edge Cases
    // =========================================================================

    public function testRecordMultiplePaymentFailures(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $this->billing->recordPaymentFailure($sub->getId());
        $this->billing->recordPaymentFailure($sub->getId());
        $result = $this->billing->recordPaymentFailure($sub->getId());

        $this->assertEquals(3, $result->getFailedPaymentAttempts());
        $this->assertEquals(SubscriptionStatus::PastDue, $result->getStatus());
    }

    public function testPaymentRecoveryAfterMultipleFailures(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $this->billing->recordPaymentFailure($sub->getId());
        $this->billing->recordPaymentFailure($sub->getId());

        $recovered = $this->billing->recordPaymentSuccess($sub->getId());

        $this->assertEquals(SubscriptionStatus::Active, $recovered->getStatus());
        $this->assertEquals(0, $recovered->getFailedPaymentAttempts());
        $this->assertNull($recovered->getLastFailedAt());
    }

    public function testTrialToActiveOnPaymentSuccess(): void
    {
        $trialEnd = (new DateTime())->modify('+14 days');
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro', $trialEnd);

        $this->assertEquals(SubscriptionStatus::Trialing, $sub->getStatus());

        $activated = $this->billing->recordPaymentSuccess($sub->getId());
        $this->assertEquals(SubscriptionStatus::Active, $activated->getStatus());
    }

    public function testSuspendedSubscriptionNotAccessible(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->recordPaymentFailure($sub->getId());

        $suspended = $this->billing->suspendSubscription($sub->getId());

        $this->assertEquals(SubscriptionStatus::Suspended, $suspended->getStatus());
        $this->assertFalse($suspended->isAccessible());
        $this->assertFalse($suspended->isTerminal());
    }

    public function testBudgetResetOnRenewal(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->setBudget($sub->getId(), 100.0);
        $this->billing->updateBudgetUsed($sub->getId(), 85.0);

        $renewed = $this->billing->renewSubscription($sub->getId());

        $this->assertEquals(0.0, $renewed->getBudgetUsed());
        $this->assertFalse($renewed->getBudgetLimitReached());
    }

    public function testBudgetNullIsUnlimited(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');

        $this->assertNull($sub->getBudget());

        // Update usage should work without hitting limit
        $updated = $this->billing->updateBudgetUsed($sub->getId(), 999999.0);
        $this->assertFalse($updated->getBudgetLimitReached());
    }

    // =========================================================================
    // Wallet Edge Cases
    // =========================================================================

    public function testMultipleWalletOperations(): void
    {
        $inv1 = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $inv2 = $this->billing->createInvoice('entity-1', 'subscription');
        $inv3 = $this->billing->createInvoice('entity-1', 'wallet_topup');

        $this->billing->addFunds('entity-1', $inv1->getId(), 100.0);
        $this->billing->deductFunds('entity-1', $inv2->getId(), 30.0);
        $this->billing->addFunds('entity-1', $inv3->getId(), 50.0);

        $this->assertEquals(120.0, $this->billing->getWalletBalance('entity-1'));
    }

    public function testWalletExactBalanceDeduction(): void
    {
        $topup = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $this->billing->addFunds('entity-1', $topup->getId(), 50.0);

        $pay = $this->billing->createInvoice('entity-1', 'subscription');
        $tx = $this->billing->deductFunds('entity-1', $pay->getId(), 50.0);

        $this->assertEquals(50.0, $tx->getAmount());
        $this->assertEquals(0.0, $this->billing->getWalletBalance('entity-1'));
    }

    public function testRefundToWalletRejectsNonPositive(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription');

        $this->expectException(Exception::class);
        $this->billing->refundToWallet('entity-1', $invoice->getId(), -5.0);
    }

    public function testDeductFundsRejectsNonPositive(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription');

        $this->expectException(Exception::class);
        $this->billing->deductFunds('entity-1', $invoice->getId(), 0.0);
    }

    // =========================================================================
    // Invoice Void/Failed Edge Cases
    // =========================================================================

    public function testVoidDraftInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 10.0],
        ]);

        $voided = $this->billing->voidInvoice($invoice->getId());
        $this->assertEquals(InvoiceStatus::Voided, $voided->getStatus());
    }

    public function testCannotPayDraftInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 10.0],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Only finalized');
        $this->billing->markInvoicePaid($invoice->getId(), 'pay-1');
    }

    public function testCannotFailDraftInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 10.0],
        ]);

        $this->expectException(Exception::class);
        $this->billing->markInvoiceFailed($invoice->getId());
    }

    public function testCannotVoidVoidedInvoice(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 10.0],
        ]);
        $this->billing->voidInvoice($invoice->getId());

        $this->expectException(Exception::class);
        $this->billing->voidInvoice($invoice->getId());
    }

    // =========================================================================
    // Event Edge Cases
    // =========================================================================

    public function testDiscountExhaustedEvent(): void
    {
        $exhaustedFired = false;
        $this->billing->on(BillingEvent::DiscountExhausted, 'test', function () use (&$exhaustedFired) {
            $exhaustedFired = true;
        });

        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->createCoupon('ONCE_EV', 'percentage', 5.0, 'once');
        $this->billing->applyDiscount('ONCE_EV', $sub->getId(), 'entity-1');

        $inv = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 100.0],
        ]);
        $this->billing->finalizeInvoice($inv->getId());

        $this->assertTrue($exhaustedFired);
    }

    public function testWalletFundedEvent(): void
    {
        $eventFired = false;
        $this->billing->on(BillingEvent::WalletFunded, 'test', function () use (&$eventFired) {
            $eventFired = true;
        });

        $inv = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $this->billing->addFunds('entity-1', $inv->getId(), 50.0);

        $this->assertTrue($eventFired);
    }

    public function testWalletDeductedEvent(): void
    {
        $eventFired = false;
        $this->billing->on(BillingEvent::WalletDeducted, 'test', function () use (&$eventFired) {
            $eventFired = true;
        });

        $topup = $this->billing->createInvoice('entity-1', 'wallet_topup');
        $this->billing->addFunds('entity-1', $topup->getId(), 100.0);
        $pay = $this->billing->createInvoice('entity-1', 'subscription');
        $this->billing->deductFunds('entity-1', $pay->getId(), 30.0);

        $this->assertTrue($eventFired);
    }

    public function testSubscriptionSuspendedEvent(): void
    {
        $eventFired = false;
        $this->billing->on(BillingEvent::SubscriptionSuspended, 'test', function () use (&$eventFired) {
            $eventFired = true;
        });

        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());
        $this->billing->suspendSubscription($sub->getId());

        $this->assertTrue($eventFired);
    }

    public function testTransactionCreatedEvent(): void
    {
        $eventFired = false;
        $this->billing->on(BillingEvent::TransactionCreated, 'test', function () use (&$eventFired) {
            $eventFired = true;
        });

        $inv = $this->billing->createInvoice('entity-1', 'subscription');
        $this->billing->createTransaction('entity-1', $inv->getId(), TransactionType::GatewayCharge->value, 50.0);

        $this->assertTrue($eventFired);
    }

    public function testUpgradeDowngradeEvents(): void
    {
        $events = [];
        $this->billing->on(BillingEvent::SubscriptionUpgradePending, 'test', function () use (&$events) {
            $events[] = 'upgrade_pending';
        });
        $this->billing->on(BillingEvent::SubscriptionUpgraded, 'test', function () use (&$events) {
            $events[] = 'upgraded';
        });
        $this->billing->on(BillingEvent::SubscriptionDowngradeScheduled, 'test', function () use (&$events) {
            $events[] = 'downgrade_scheduled';
        });
        $this->billing->on(BillingEvent::SubscriptionDowngraded, 'test', function () use (&$events) {
            $events[] = 'downgraded';
        });

        $sub = $this->billing->createSubscription('entity-1', 'plan-basic');
        $this->billing->recordPaymentSuccess($sub->getId());

        $this->billing->requestUpgrade($sub->getId(), 'plan-pro');
        $this->billing->finalizeUpgrade($sub->getId());

        $this->billing->requestDowngrade($sub->getId(), 'plan-basic');
        $this->billing->applyPendingDowngrade($sub->getId());

        $this->assertEquals(['upgrade_pending', 'upgraded', 'downgrade_scheduled', 'downgraded'], $events);
    }

    // =========================================================================
    // finalizeInvoice Edge Cases
    // =========================================================================

    public function testFinalizeInvoiceWithMultipleDiscounts(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        // Apply two discounts: invoice-level 10% + line-level 50% on bandwidth
        $this->billing->createCoupon('INV10', 'percentage', 10.0, 'forever');
        $this->billing->createCoupon('BW50', 'percentage', 50.0, 'forever', null, [
            'scope' => ['resources' => ['bandwidth']],
        ]);
        $this->billing->applyDiscount('INV10', $sub->getId(), 'entity-1');
        $this->billing->applyDiscount('BW50', $sub->getId(), 'entity-1');

        $invoice = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 100.00],
            ['type' => 'usage', 'description' => 'BW', 'amount' => 20.00, 'resource' => 'bandwidth'],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        // Line-level: 50% of 20 = 10. Invoice-level: 10% of 120 = 12.
        $this->assertEquals(120.0, $finalized->getSubtotal());
        $this->assertEquals(22.0, $finalized->getDiscountTotal());
        $this->assertEquals(98.0, $finalized->getTotal());

        $discountItems = $finalized->getItemsByType(Invoice::ITEM_TYPE_DISCOUNT);
        $this->assertCount(2, $discountItems);
    }

    public function testFinalizeInvoiceFixedDiscountExceedingSubtotal(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        // $500 fixed discount on a $100 invoice
        $this->billing->createCoupon('BIG', 'fixed', 500.0, 'once');
        $this->billing->applyDiscount('BIG', $sub->getId(), 'entity-1');

        $invoice = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 100.00],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        // Fixed discount capped at subtotal
        $this->assertEquals(100.0, $finalized->getSubtotal());
        $this->assertEquals(100.0, $finalized->getDiscountTotal());
        $this->assertEquals(0.0, $finalized->getTotal());
    }

    public function testFinalizeInvoiceForeverDiscountDoesNotDecrement(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $this->billing->createCoupon('FOREVER', 'percentage', 5.0, 'forever');
        $discount = $this->billing->applyDiscount('FOREVER', $sub->getId(), 'entity-1');

        $this->assertNull($discount->getCyclesRemaining());

        // Finalize first invoice
        $inv1 = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 100.00],
        ]);
        $this->billing->finalizeInvoice($inv1->getId());

        // Discount should still be active with null cycles
        $discounts = $this->billing->listActiveDiscounts($sub->getId());
        $this->assertCount(1, $discounts);
        $this->assertNull($discounts[0]->getCyclesRemaining());
        $this->assertTrue($discounts[0]->isActive());
    }

    public function testFinalizeInvoiceRepeatingDiscountCountdown(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        $this->billing->createCoupon('REP2', 'percentage', 10.0, 'repeating', 2);
        $discount = $this->billing->applyDiscount('REP2', $sub->getId(), 'entity-1');
        $this->assertEquals(2, $discount->getCyclesRemaining());

        // First finalization: 2 -> 1
        $inv1 = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 100.00],
        ]);
        $this->billing->finalizeInvoice($inv1->getId());

        $discounts = $this->billing->listActiveDiscounts($sub->getId());
        $this->assertCount(1, $discounts);
        $this->assertEquals(1, $discounts[0]->getCyclesRemaining());

        // Second finalization: 1 -> 0 -> exhausted
        $inv2 = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 100.00],
        ]);
        $this->billing->finalizeInvoice($inv2->getId());

        $activeDiscounts = $this->billing->listActiveDiscounts($sub->getId());
        $this->assertCount(0, $activeDiscounts);
    }

    public function testFinalizeInvoiceLineLevelDiscountNoMatchingResources(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        // Discount targets 'storage', but invoice only has 'bandwidth'
        $this->billing->createCoupon('STOR50', 'percentage', 50.0, 'forever', null, [
            'scope' => ['resources' => ['storage']],
        ]);
        $this->billing->applyDiscount('STOR50', $sub->getId(), 'entity-1');

        $invoice = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 100.00],
            ['type' => 'usage', 'description' => 'BW', 'amount' => 20.00, 'resource' => 'bandwidth'],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        // No discount should be applied
        $this->assertEquals(120.0, $finalized->getSubtotal());
        $this->assertEquals(0.0, $finalized->getDiscountTotal());
        $this->assertEquals(120.0, $finalized->getTotal());
    }

    public function testFinalizeInvoiceEmptyItems(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'subscription', null, []);

        $finalized = $this->billing->finalizeInvoice($invoice->getId());

        $this->assertEquals(0.0, $finalized->getSubtotal());
        $this->assertEquals(0.0, $finalized->getTotal());
        $this->assertEquals(InvoiceStatus::Finalized, $finalized->getStatus());
    }

    public function testFinalizeInvoiceOneOffWithoutSubscription(): void
    {
        $invoice = $this->billing->createInvoice('entity-1', 'addon_purchase', null, [
            ['type' => 'addon', 'description' => 'Custom Domain', 'amount' => 9.99],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId(), [
            'taxRate' => 20.0,
            'taxDescription' => 'GST',
        ]);

        $this->assertEquals(9.99, $finalized->getSubtotal());
        $this->assertEquals(2.0, $finalized->getTaxTotal());
        $this->assertEquals(11.99, $finalized->getTotal());
        $this->assertNull($finalized->getSubscriptionId());
    }

    public function testFinalizeInvoiceTaxAfterDiscounts(): void
    {
        $sub = $this->billing->createSubscription('entity-1', 'plan-pro');
        $this->billing->recordPaymentSuccess($sub->getId());

        // 50% discount on $200 = $100 taxable, 10% tax = $10
        $this->billing->createCoupon('HALF', 'percentage', 50.0, 'once');
        $this->billing->applyDiscount('HALF', $sub->getId(), 'entity-1');

        $invoice = $this->billing->createInvoice('entity-1', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 200.00],
        ]);

        $finalized = $this->billing->finalizeInvoice($invoice->getId(), [
            'taxRate' => 10.0,
        ]);

        $this->assertEquals(200.0, $finalized->getSubtotal());
        $this->assertEquals(100.0, $finalized->getDiscountTotal());
        $this->assertEquals(10.0, $finalized->getTaxTotal()); // 10% of (200-100)
        $this->assertEquals(110.0, $finalized->getTotal()); // 200 - 100 + 10
    }

    // =========================================================================
    // Facade List Methods Coverage
    // =========================================================================

    public function testListCoupons(): void
    {
        $this->billing->createCoupon('LIST_A', 'percentage', 5.0, 'once');
        $this->billing->createCoupon('LIST_B', 'fixed', 10.0, 'forever');

        $coupons = $this->billing->listCoupons();
        $this->assertGreaterThanOrEqual(2, count($coupons));
        $this->assertInstanceOf(Coupon::class, $coupons[0]);
    }

    public function testGetTransaction(): void
    {
        $invoice = $this->billing->createInvoice('entity-tx', 'subscription');
        $tx = $this->billing->createTransaction('entity-tx', $invoice->getId(), TransactionType::GatewayCharge->value, 10.0);

        $fetched = $this->billing->getTransaction($tx->getId());
        $this->assertEquals($tx->getId(), $fetched->getId());
    }

    public function testGetTransactionNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->billing->getTransaction('nonexistent');
    }

    public function testGetInvoiceNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->billing->getInvoice('nonexistent');
    }

    public function testGetDiscountNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->billing->getDiscount('nonexistent');
    }

    // =========================================================================
    // Payment Orchestration
    // =========================================================================

    public function testPayInvoiceWithManualAdapter(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-pay', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Pro Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        $response = $billing->payInvoice($invoice->getId(), 'cus_123');

        $this->assertTrue($response->isSucceeded());
        $this->assertNotNull($response->providerPaymentId);

        $paid = $billing->getInvoice($invoice->getId());
        $this->assertEquals(InvoiceStatus::Paid, $paid->getStatus());
        $this->assertNotNull($paid->getPaidAt());
        $this->assertEquals(50.0, $paid->getGatewayCharged());
    }

    public function testPayInvoiceWalletFirst(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        // Add wallet funds
        $topup = $billing->createInvoice('entity-wf', 'wallet_topup');
        $billing->addFunds('entity-wf', $topup->getId(), 30.0);

        // Create $50 invoice — wallet covers $30, gateway covers $20
        $invoice = $billing->createInvoice('entity-wf', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        $response = $billing->payInvoice($invoice->getId(), 'cus_123');

        $this->assertTrue($response->isSucceeded());
        $paid = $billing->getInvoice($invoice->getId());
        $this->assertEquals(30.0, $paid->getWalletDeducted());
        $this->assertEquals(20.0, $paid->getGatewayCharged());
        $this->assertEquals(0.0, $billing->getWalletBalance('entity-wf'));
    }

    public function testPayInvoiceFullyByWallet(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $topup = $billing->createInvoice('entity-fw', 'wallet_topup');
        $billing->addFunds('entity-fw', $topup->getId(), 100.0);

        $invoice = $billing->createInvoice('entity-fw', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 25.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        $response = $billing->payInvoice($invoice->getId(), 'cus_123');

        $this->assertTrue($response->isSucceeded());
        $paid = $billing->getInvoice($invoice->getId());
        $this->assertEquals(25.0, $paid->getWalletDeducted());
        $this->assertEquals(0.0, $paid->getGatewayCharged());
        $this->assertEquals(75.0, $billing->getWalletBalance('entity-fw'));
    }

    public function testPayInvoiceNoPaymentAdapterThrows(): void
    {
        // No payment adapter — wallet-only mode
        $billing = new Billing(new InMemoryAdapter());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-np', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Payment adapter required');
        $billing->payInvoice($invoice->getId(), 'cus_123');
    }

    public function testPayInvoiceNoAdapterFullyWallet(): void
    {
        // No payment adapter but wallet covers everything
        $billing = new Billing(new InMemoryAdapter());
        $billing->setup();

        $topup = $billing->createInvoice('entity-nw', 'wallet_topup');
        $billing->addFunds('entity-nw', $topup->getId(), 100.0);

        $invoice = $billing->createInvoice('entity-nw', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        $response = $billing->payInvoice($invoice->getId(), 'cus_123');
        $this->assertTrue($response->isSucceeded());
    }

    public function testPayInvoiceOnlyFinalizedAllowed(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-draft', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 10.00],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Only finalized');
        $billing->payInvoice($invoice->getId(), 'cus_123');
    }

    public function testConfirmPayment(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-cp', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        // Simulate a pending payment by paying and getting the provider ID
        $response = $billing->payInvoice($invoice->getId(), 'cus_123');
        // Manual adapter always succeeds — but we can test confirmPayment on a new invoice

        // Create another invoice, finalize, and manually simulate 3DS scenario
        $inv2 = $billing->createInvoice('entity-cp', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan 2', 'amount' => 30.00],
        ]);
        $billing->finalizeInvoice($inv2->getId());

        // Directly call confirmPayment (simulating webhook after 3DS)
        $confirmed = $billing->confirmPayment($inv2->getId(), 'pi_test_123');
        $this->assertEquals(InvoiceStatus::Paid, $confirmed->getStatus());
        $this->assertNotNull($confirmed->getPaidAt());
    }

    public function testFailPayment(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-fp', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        $failed = $billing->failPayment($invoice->getId(), 'pi_failed_123');
        $this->assertEquals(InvoiceStatus::Failed, $failed->getStatus());
    }

    public function testRetryPayment(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-rp', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        // Fail first
        $billing->failPayment($invoice->getId(), 'pi_fail');

        // Retry — Manual adapter always succeeds
        $response = $billing->retryPayment($invoice->getId(), 'cus_123');
        $this->assertTrue($response->isSucceeded());

        $paid = $billing->getInvoice($invoice->getId());
        $this->assertEquals(InvoiceStatus::Paid, $paid->getStatus());
    }

    public function testRetryPaymentOnlyFailedAllowed(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-ro', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Only failed');
        $billing->retryPayment($invoice->getId(), 'cus_123');
    }

    public function testRefundInvoice(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-rf', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());
        $billing->payInvoice($invoice->getId(), 'cus_123');

        $creditNote = $billing->refundInvoice($invoice->getId());

        $this->assertTrue($creditNote->isCreditNote());
        $this->assertEquals($invoice->getId(), $creditNote->getReferenceInvoiceId());
    }

    public function testRefundInvoicePartial(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-rp2', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 100.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());
        $billing->payInvoice($invoice->getId(), 'cus_123');

        $creditNote = $billing->refundInvoice($invoice->getId(), 30.0, 'Partial refund');
        $this->assertTrue($creditNote->isCreditNote());
    }

    public function testRefundInvoiceOnlyPaidAllowed(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        $invoice = $billing->createInvoice('entity-rn', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Only paid');
        $billing->refundInvoice($invoice->getId());
    }

    public function testRefundInvoiceWithWalletDeduction(): void
    {
        $billing = new Billing(new InMemoryAdapter(), new \Utopia\Billing\Payment\Manual());
        $billing->setup();

        // Fund wallet
        $topup = $billing->createInvoice('entity-rw', 'wallet_topup');
        $billing->addFunds('entity-rw', $topup->getId(), 30.0);

        // Pay with wallet-first
        $invoice = $billing->createInvoice('entity-rw', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 50.00],
        ]);
        $billing->finalizeInvoice($invoice->getId());
        $billing->payInvoice($invoice->getId(), 'cus_123');

        $this->assertEquals(0.0, $billing->getWalletBalance('entity-rw'));

        // Full refund — should refund both gateway and wallet portions
        $billing->refundInvoice($invoice->getId());

        // Wallet should have the $30 back
        $this->assertEquals(30.0, $billing->getWalletBalance('entity-rw'));
    }

    public function testPaymentResponseHelpers(): void
    {
        $succeeded = new \Utopia\Billing\PaymentResponse(status: 'succeeded', providerPaymentId: 'pi_1');
        $this->assertTrue($succeeded->isSucceeded());
        $this->assertFalse($succeeded->isPending());
        $this->assertFalse($succeeded->isFailed());

        $action = new \Utopia\Billing\PaymentResponse(status: 'requires_action', clientSecret: 'pi_secret');
        $this->assertTrue($action->requiresAction());
        $this->assertTrue($action->isPending());
        $this->assertFalse($action->isSucceeded());

        $processing = new \Utopia\Billing\PaymentResponse(status: 'processing');
        $this->assertTrue($processing->isProcessing());
        $this->assertTrue($processing->isPending());

        $failed = new \Utopia\Billing\PaymentResponse(status: 'failed', errorCode: 'card_declined', errorMessage: 'Declined');
        $this->assertTrue($failed->isFailed());
        $this->assertFalse($failed->isPending());
    }

    public function testManualAdapterName(): void
    {
        $manual = new \Utopia\Billing\Payment\Manual();
        $this->assertEquals('manual', $manual->getName());
    }

    public function testManualAdapterRefund(): void
    {
        $manual = new \Utopia\Billing\Payment\Manual();
        $response = $manual->refund('pi_123', 10.0, 'test');
        $this->assertTrue($response->isSucceeded());
        $this->assertEquals('pi_123', $response->providerPaymentId);
    }
}
