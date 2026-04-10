<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E\Billing;

use DateTime;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Billing\Adapter\Database as DatabaseAdapter;
use Utopia\Billing\Billing;
use Utopia\Billing\Event;
use Utopia\Billing\ChangeType;
use Utopia\Billing\CouponDuration;
use Utopia\Billing\CouponType;
use Utopia\Billing\DiscountStatus;
use Utopia\Billing\Exception;
use Utopia\Billing\Invoice;
use Utopia\Billing\InvoiceStatus;
use Utopia\Billing\SubscriptionStatus;
use Utopia\Billing\TransactionType;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;

/**
 * End-to-end tests for the Billing library using a real MariaDB database.
 *
 * Run via: docker compose up -d && docker compose exec tests vendor/bin/phpunit --group e2e
 *
 * @group e2e
 */
class BillingTest extends TestCase
{
    private static ?Billing $billing = null;

    public static function setUpBeforeClass(): void
    {
        $host = \getenv('MARIADB_HOST') ?: 'mariadb';
        $port = \getenv('MARIADB_PORT') ?: '3306';
        $user = \getenv('MARIADB_USER') ?: 'root';
        $pass = \getenv('MARIADB_PASSWORD') ?: 'password';
        $name = \getenv('MARIADB_DATABASE') ?: 'billing_test';

        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            ]
        );

        $cache = new Cache(new NoCache());
        $adapter = new MariaDB($pdo);
        $database = new Database($adapter, $cache);
        $database->setDatabase($name);
        $database->setNamespace('billing_e2e');

        // Ensure MySQL database exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}`");

        // Check if utopia metadata table exists, create it if not
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([$name, 'billing_e2e__metadata']);
        $metadataExists = (int) $stmt->fetchColumn() > 0;

        if (! $metadataExists) {
            $database->create();
        }

        self::$billing = new Billing(new DatabaseAdapter($database));
    }

    protected function setUp(): void
    {
        if (self::$billing === null) {
            $this->markTestSkipped('Database not available');
        }
    }

    private static function billing(): Billing
    {
        \assert(self::$billing instanceof Billing);

        return self::$billing;
    }

    // =========================================================================
    // Setup
    // =========================================================================

    public function testSetup(): void
    {
        self::billing()->setup();

        // Calling setup again should be idempotent
        self::billing()->setup();

        $this->assertTrue(true); // If we got here, setup didn't throw
    }

    // =========================================================================
    // Full Subscription Lifecycle
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testSubscriptionLifecycle(): void
    {
        $billing = self::billing();

        // 1. Create subscription (incomplete)
        $sub = $billing->createSubscription('e2e-org-1', 'plan-starter', null, 'organization');
        $this->assertEquals(SubscriptionStatus::Incomplete, $sub->getStatus());
        $this->assertEquals('e2e-org-1', $sub->getEntityId());
        $this->assertEquals('plan-starter', $sub->getPlanId());

        // 2. Payment succeeds -> active
        $active = $billing->recordPaymentSuccess($sub->getId());
        $this->assertEquals(SubscriptionStatus::Active, $active->getStatus());

        // 3. Fetch by ID
        $fetched = $billing->getSubscription($sub->getId());
        $this->assertEquals($sub->getId(), $fetched->getId());
        $this->assertEquals(SubscriptionStatus::Active, $fetched->getStatus());

        // 4. Fetch active subscription
        $activeSub = $billing->getActiveSubscription('e2e-org-1');
        $this->assertNotNull($activeSub);
        $this->assertEquals($sub->getId(), $activeSub->getId());

        // 5. Upgrade flow
        $billing->requestUpgrade($sub->getId(), 'plan-pro');
        $upgraded = $billing->finalizeUpgrade($sub->getId());
        $this->assertEquals('plan-pro', $upgraded->getPlanId());
        $this->assertFalse($upgraded->hasPendingChange());

        // 6. Downgrade flow (deferred)
        $billing->requestDowngrade($sub->getId(), 'plan-starter');
        $downgraded = $billing->getSubscription($sub->getId());
        $this->assertEquals('plan-pro', $downgraded->getPlanId()); // Still pro
        $this->assertEquals(ChangeType::Downgrade, $downgraded->getPendingChangeType());

        // 7. Renew -> applies downgrade
        $renewed = $billing->renewSubscription($sub->getId());
        $this->assertEquals('plan-starter', $renewed->getPlanId());
        $this->assertNull($renewed->getPendingPlanId());

        // 8. Cancel at period end
        $canceling = $billing->cancelSubscription($sub->getId(), true);
        $this->assertEquals(SubscriptionStatus::Canceling, $canceling->getStatus());
        $this->assertTrue($canceling->getCancelAtPeriodEnd());

        // 9. Renew completes cancellation
        $canceled = $billing->renewSubscription($sub->getId());
        $this->assertEquals(SubscriptionStatus::Canceled, $canceled->getStatus());
        $this->assertTrue($canceled->isTerminal());
    }

    // =========================================================================
    // Full Invoice + Discount + Tax Flow
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testInvoiceFinalization(): void
    {
        $billing = self::billing();

        // Create subscription
        $sub = $billing->createSubscription('e2e-org-2', 'plan-pro');
        $billing->recordPaymentSuccess($sub->getId());

        // Create coupons
        $billing->createCoupon('E2E_10PCT', 'percentage', 10.0, 'repeating', 3);
        $billing->createCoupon('E2E_BW50', 'percentage', 50.0, 'forever', null, [
            'scope' => ['resources' => ['bandwidth']],
        ]);

        // Apply discounts
        $invoiceDiscount = $billing->applyDiscount('E2E_10PCT', $sub->getId(), 'e2e-org-2');
        $lineDiscount = $billing->applyDiscount('E2E_BW50', $sub->getId(), 'e2e-org-2');

        $this->assertEquals(CouponType::Percentage, $invoiceDiscount->getType());
        $this->assertEquals(3, $invoiceDiscount->getCyclesRemaining());
        $this->assertTrue($lineDiscount->isLineLevel());

        // Create invoice with line items
        $invoice = $billing->createInvoice('e2e-org-2', 'subscription', $sub->getId(), [
            ['type' => 'plan', 'description' => 'Pro Plan - April 2026', 'amount' => 15.00],
            ['type' => 'usage', 'description' => 'Bandwidth (42.5 GB extra)', 'amount' => 3.40, 'resource' => 'bandwidth', 'quantity' => 42.5, 'unit' => 'GB', 'unitPrice' => 0.08],
            ['type' => 'usage', 'description' => 'Executions (150K extra)', 'amount' => 0.30, 'resource' => 'executions', 'quantity' => 150000, 'unitPrice' => 0.000002],
        ]);

        $this->assertEquals(InvoiceStatus::Draft, $invoice->getStatus());
        $this->assertCount(3, $invoice->getItems());

        // Add another item
        $invoice = $billing->addInvoiceItem($invoice->getId(), [
            'type' => 'addon',
            'description' => 'Custom Domain',
            'amount' => 5.00,
        ]);
        $this->assertCount(4, $invoice->getItems());

        // Finalize with tax
        $finalized = $billing->finalizeInvoice($invoice->getId(), [
            'taxRate' => 13.0,
            'taxDescription' => 'GST',
            'taxableTypes' => ['plan', 'usage', 'addon'],
        ]);

        $this->assertEquals(InvoiceStatus::Finalized, $finalized->getStatus());
        $this->assertNotEmpty($finalized->getNumber());
        $this->assertGreaterThan(0.0, $finalized->getSubtotal());
        $this->assertGreaterThan(0.0, $finalized->getDiscountTotal());
        $this->assertGreaterThan(0.0, $finalized->getTaxTotal());
        $this->assertTrue($finalized->isFinalized());

        // Verify discount items were added
        $discountItems = $finalized->getItemsByType(Invoice::ITEM_TYPE_DISCOUNT);
        $this->assertGreaterThanOrEqual(1, \count($discountItems));

        // Verify tax item was added
        $taxItems = $finalized->getItemsByType(Invoice::ITEM_TYPE_TAX);
        $this->assertCount(1, $taxItems);
        $this->assertEquals(13.0, $taxItems[0]['rate']);

        // Verify discount cycles decremented
        $discounts = $billing->listActiveDiscounts($sub->getId());
        foreach ($discounts as $d) {
            if ($d->getCyclesRemaining() !== null) {
                $this->assertEquals(2, $d->getCyclesRemaining()); // 3 -> 2
            }
        }

        // Mark paid
        $paid = $billing->markInvoicePaid($invoice->getId(), 'pi_stripe_e2e_123');
        $this->assertEquals(InvoiceStatus::Paid, $paid->getStatus());
        $this->assertNotNull($paid->getPaidAt());

        // List invoices
        $invoices = $billing->listInvoices('e2e-org-2');
        $this->assertGreaterThanOrEqual(1, \count($invoices));

        // Create credit note
        $creditNote = $billing->createCreditNote($invoice->getId(), [
            ['type' => 'refund', 'description' => 'Partial refund', 'amount' => -5.00],
        ]);
        $this->assertTrue($creditNote->isCreditNote());
        $this->assertEquals($invoice->getId(), $creditNote->getReferenceInvoiceId());
    }

    // =========================================================================
    // Wallet + Transactions
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testWalletTransactions(): void
    {
        $billing = self::billing();

        // Create wallet
        $wallet = $billing->getOrCreateWallet('e2e-org-3');
        $this->assertEquals(0.0, $wallet->getBalance());
        $this->assertEquals('USD', $wallet->getCurrency());

        // Idempotent
        $same = $billing->getOrCreateWallet('e2e-org-3');
        $this->assertEquals($wallet->getId(), $same->getId());

        // Top up
        $topupInvoice = $billing->createInvoice('e2e-org-3', 'wallet_topup');
        $topupTx = $billing->addFunds('e2e-org-3', $topupInvoice->getId(), 200.0);
        $this->assertEquals(TransactionType::WalletTopup, $topupTx->getType());
        $this->assertEquals(200.0, $billing->getWalletBalance('e2e-org-3'));

        // Deduct
        $payInvoice = $billing->createInvoice('e2e-org-3', 'subscription');
        $deductTx = $billing->deductFunds('e2e-org-3', $payInvoice->getId(), 75.50);
        $this->assertEquals(TransactionType::WalletDeduction, $deductTx->getType());
        $this->assertEquals(124.50, $billing->getWalletBalance('e2e-org-3'));

        // Refund
        $refundTx = $billing->refundToWallet('e2e-org-3', $payInvoice->getId(), 25.50);
        $this->assertEquals(TransactionType::WalletRefund, $refundTx->getType());
        $this->assertEquals(150.0, $billing->getWalletBalance('e2e-org-3'));

        // Gateway transaction
        $gatewayTx = $billing->createTransaction(
            'e2e-org-3',
            $payInvoice->getId(),
            TransactionType::GatewayCharge->value,
            49.99,
            null,
            'pi_stripe_gw_123',
        );
        $this->assertTrue($gatewayTx->isGatewayTransaction());
        $this->assertEquals('pi_stripe_gw_123', $gatewayTx->getProviderPaymentId());

        // List by entity
        $transactions = $billing->listTransactions('e2e-org-3');
        $this->assertGreaterThanOrEqual(4, \count($transactions));

        // List by invoice
        $invoiceTxs = $billing->listInvoiceTransactions($payInvoice->getId());
        $this->assertGreaterThanOrEqual(2, \count($invoiceTxs));

        // Insufficient balance
        $this->expectException(Exception::class);
        $billing->deductFunds('e2e-org-3', $payInvoice->getId(), 999.99);
    }

    // =========================================================================
    // Dunning + Budget
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testDunningBudget(): void
    {
        $billing = self::billing();

        $sub = $billing->createSubscription('e2e-org-4', 'plan-pro');
        $billing->recordPaymentSuccess($sub->getId());

        // Set budget
        $billing->setBudget($sub->getId(), 100.0);
        $sub = $billing->getSubscription($sub->getId());
        $this->assertEquals(100.0, $sub->getBudget());

        // Update usage
        $billing->updateBudgetUsed($sub->getId(), 50.0);
        $sub = $billing->getSubscription($sub->getId());
        $this->assertEquals(50.0, $sub->getBudgetUsed());
        $this->assertFalse($sub->getBudgetLimitReached());

        // Hit limit
        $billing->updateBudgetUsed($sub->getId(), 100.0);
        $sub = $billing->getSubscription($sub->getId());
        $this->assertTrue($sub->getBudgetLimitReached());

        // Simulate payment failures
        $billing->recordPaymentFailure($sub->getId());
        $billing->recordPaymentFailure($sub->getId());
        $sub = $billing->getSubscription($sub->getId());
        $this->assertEquals(SubscriptionStatus::PastDue, $sub->getStatus());
        $this->assertEquals(2, $sub->getFailedPaymentAttempts());

        // Recovery
        $billing->recordPaymentSuccess($sub->getId());
        $sub = $billing->getSubscription($sub->getId());
        $this->assertEquals(SubscriptionStatus::Active, $sub->getStatus());
        $this->assertEquals(0, $sub->getFailedPaymentAttempts());

        // Fail again and suspend
        $billing->recordPaymentFailure($sub->getId());
        $billing->recordPaymentFailure($sub->getId());
        $billing->recordPaymentFailure($sub->getId());
        $suspended = $billing->suspendSubscription($sub->getId());
        $this->assertEquals(SubscriptionStatus::Suspended, $suspended->getStatus());
        $this->assertFalse($suspended->isAccessible());
    }

    // =========================================================================
    // Coupon Edge Cases
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testCouponLifecycle(): void
    {
        $billing = self::billing();

        // Create with options
        $coupon = $billing->createCoupon('E2E_FIXED50', 'fixed', 50.0, 'once', null, [
            'currency' => 'USD',
            'maxRedemptions' => 2,
        ]);

        $this->assertTrue($coupon->isFixed());
        $this->assertEquals(50.0, $coupon->getValue());
        $this->assertEquals(CouponDuration::Once, $coupon->getDuration());
        $this->assertEquals(2, $coupon->getMaxRedemptions());

        // Fetch by code
        $fetched = $billing->getCoupon('E2E_FIXED50');
        $this->assertEquals($coupon->getId(), $fetched->getId());

        // Apply twice
        $sub1 = $billing->createSubscription('e2e-org-cpn-1', 'plan-pro');
        $sub2 = $billing->createSubscription('e2e-org-cpn-2', 'plan-pro');

        $billing->applyDiscount('E2E_FIXED50', $sub1->getId(), 'e2e-org-cpn-1');
        $billing->applyDiscount('E2E_FIXED50', $sub2->getId(), 'e2e-org-cpn-2');

        // Should now be maxed out
        $coupon = $billing->getCoupon('E2E_FIXED50');
        $this->assertEquals(2, $coupon->getTimesRedeemed());
        $this->assertFalse($coupon->isRedeemable());

        // Deactivate
        $deactivated = $billing->deactivateCoupon('E2E_FIXED50');
        $this->assertFalse($deactivated->isActive());

        // List coupons
        $coupons = $billing->listCoupons();
        $this->assertGreaterThanOrEqual(1, \count($coupons));
    }

    // =========================================================================
    // Discount Cancel
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testDiscountCancel(): void
    {
        $billing = self::billing();

        $sub = $billing->createSubscription('e2e-org-disc', 'plan-pro');
        $billing->createCoupon('E2E_CANCEL', 'percentage', 15.0, 'forever');

        $discount = $billing->applyDiscount('E2E_CANCEL', $sub->getId(), 'e2e-org-disc');
        $this->assertTrue($discount->isActive());

        $cancelled = $billing->cancelDiscount($discount->getId());
        $this->assertEquals(DiscountStatus::Cancelled, $cancelled->getStatus());
        $this->assertNotNull($cancelled->getCancelledAt());

        // No active discounts
        $active = $billing->listActiveDiscounts($sub->getId());
        $this->assertCount(0, $active);
    }

    // =========================================================================
    // Invoice Void / Credit Note
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testInvoiceVoid(): void
    {
        $billing = self::billing();

        // Void a finalized invoice
        $inv = $billing->createInvoice('e2e-org-void', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 25.0],
        ]);
        $billing->finalizeInvoice($inv->getId());
        $voided = $billing->voidInvoice($inv->getId());
        $this->assertEquals(InvoiceStatus::Voided, $voided->getStatus());

        // Cannot void again
        try {
            $billing->voidInvoice($inv->getId());
            $this->fail('Expected exception');
        } catch (Exception) {
            $this->assertTrue(true);
        }
    }

    // =========================================================================
    // Period & Proration
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testPeriodProration(): void
    {
        $billing = self::billing();

        $sub = $billing->createSubscription('e2e-org-period', 'plan-pro');

        $period = $billing->getCurrentPeriod($sub->getId());
        $this->assertGreaterThan(0, $period->getDays());
        $this->assertTrue($period->contains(new DateTime()));

        $prorated = $billing->calculateProration($sub->getId(), 'plan-enterprise', 30.0);
        $this->assertGreaterThan(0.0, $prorated);
        $this->assertLessThanOrEqual(30.0, $prorated);
    }

    // =========================================================================
    // Events Fire During E2E Flow
    // =========================================================================

    /**
     * @depends testSetup
     */
    public function testEvents(): void
    {
        $billing = self::billing();
        $events = [];

        $billing->on(Event::SubscriptionCreated, 'test', function () use (&$events) {
            $events[] = 'subscription.created';
        });
        $billing->on(Event::InvoiceFinalized, 'test', function () use (&$events) {
            $events[] = 'invoice.finalized';
        });
        $billing->on(Event::InvoicePaid, 'test', function () use (&$events) {
            $events[] = 'invoice.paid';
        });
        $billing->on(Event::WalletFunded, 'test', function () use (&$events) {
            $events[] = 'wallet.funded';
        });

        $sub = $billing->createSubscription('e2e-org-events', 'plan-pro');

        $inv = $billing->createInvoice('e2e-org-events', 'subscription', null, [
            ['type' => 'plan', 'description' => 'Plan', 'amount' => 10.0],
        ]);
        $billing->finalizeInvoice($inv->getId());
        $billing->markInvoicePaid($inv->getId(), 'pay-e2e');

        $topup = $billing->createInvoice('e2e-org-events', 'wallet_topup');
        $billing->addFunds('e2e-org-events', $topup->getId(), 25.0);

        $this->assertContains('subscription.created', $events);
        $this->assertContains('invoice.finalized', $events);
        $this->assertContains('invoice.paid', $events);
        $this->assertContains('wallet.funded', $events);
    }
}
