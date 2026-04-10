<?php

declare(strict_types=1);

namespace Utopia\Billing;

use DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;

/**
 * Billing
 *
 * Main facade for the billing library. Wraps the abstract Adapter to provide
 * a high-level API for subscription management, invoicing, coupons/discounts,
 * wallets, and unified transactions.
 *
 * Usage:
 * ```php
 * $billing = new Billing(new DatabaseAdapter($database));
 * $billing->setup();
 * $subscription = $billing->createSubscription('entity-123', 'plan-pro');
 * ```
 */
class Billing
{
    /**
     * Registered event listeners (named, like utopia-php/database).
     *
     * @var array<string, array<string, callable>>
     */
    protected array $listeners = [];

    /**
     * Options for the billing instance.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Billing constructor.
     *
     * @param  Adapter  $adapter  The persistence adapter
     * @param  array<string, mixed>  $options  Options: 'invoiceNumberFormat' => 'INV-{year}-{sequence}'
     */
    public function __construct(
        protected Adapter $adapter,
        array $options = [],
    ) {
        $this->options = \array_merge([
            'invoiceNumberFormat' => 'INV-{year}-{sequence}',
        ], $options);
    }

    /**
     * Set up all required collections in the underlying storage.
     *
     *
     * @throws Exception
     */
    public function setup(): void
    {
        $this->adapter->setup();
    }

    // -------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------

    /**
     * Register an event listener.
     *
     * Follows the same pattern as utopia-php/database: named listeners
     * with a Event enum. Pass null callback to unregister.
     *
     * @param  Event  $event  The event
     * @param  string  $name  Unique listener name (for removal)
     * @param  callable|null  $callback  The callback (null to remove)
     */
    public function on(Event $event, string $name, ?callable $callback): self
    {
        if ($callback === null) {
            unset($this->listeners[$event->value][$name]);

            return $this;
        }

        $this->listeners[$event->value][$name] = $callback;

        return $this;
    }

    /**
     * Emit an event to all registered listeners.
     *
     * @param  Event  $event  The event
     * @param  mixed  $data  The event payload
     */
    protected function emit(Event $event, mixed $data = null): void
    {
        foreach ($this->listeners[$event->value] ?? [] as $callback) {
            $callback($data);
        }
    }

    // -------------------------------------------------------------------------
    // Subscriptions
    // -------------------------------------------------------------------------

    /**
     * Create a new subscription.
     *
     * If a trialEnd is provided, the subscription starts in 'trialing' status.
     * Otherwise it starts as 'incomplete' (awaiting first payment).
     *
     * @param  string  $entityId  The entity ID (user, team, org)
     * @param  string  $planId  The plan identifier
     * @param  DateTime|null  $trialEnd  Optional trial end date
     * @param  string  $entityType  The entity type (default: 'organization')
     * @param  array<string, mixed>  $metadata  Optional metadata
     *
     * @throws Exception
     */
    public function createSubscription(
        string $entityId,
        string $planId,
        ?DateTime $trialEnd = null,
        string $entityType = 'organization',
        array $metadata = [],
    ): Subscription {
        $now = new DateTime();
        $periodEnd = (clone $now)->modify('+1 month');

        $isTrialing = $trialEnd !== null && $trialEnd > $now;

        $data = [
            '$id' => ID::unique(),
            '$permissions' => [],
            'entityId' => $entityId,
            'entityType' => $entityType,
            'planId' => $planId,
            'status' => $isTrialing ? SubscriptionStatus::Trialing->value : SubscriptionStatus::Incomplete->value,
            'currentPeriodStart' => $now->format('Y-m-d\TH:i:s.000+00:00'),
            'currentPeriodEnd' => ($isTrialing ? $trialEnd : $periodEnd)->format('Y-m-d\TH:i:s.000+00:00'),
            'trialStart' => $isTrialing ? $now->format('Y-m-d\TH:i:s.000+00:00') : null,
            'trialEnd' => $isTrialing ? $trialEnd->format('Y-m-d\TH:i:s.000+00:00') : null,
            'pendingPlanId' => null,
            'pendingChangeType' => null,
            'pendingChangedAt' => null,
            'pendingExpiresAt' => null,
            'pendingInvoiceId' => null,
            'budget' => null,
            'budgetUsed' => 0.0,
            'budgetLimitReached' => false,
            'failedPaymentAttempts' => 0,
            'nextRetryAt' => null,
            'lastFailedAt' => null,
            'cancelAtPeriodEnd' => false,
            'metadata' => \json_encode($metadata),
        ];

        $doc = $this->adapter->createSubscription(new Document($data));
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionCreated, $subscription);

        return $subscription;
    }

    /**
     * Get a subscription by ID.
     *
     * @param  string  $id  The subscription ID
     *
     * @throws Exception If not found
     */
    public function getSubscription(string $id): Subscription
    {
        $doc = $this->adapter->getSubscription($id);

        if ($doc->isEmpty()) {
            throw new Exception('Subscription not found');
        }

        return new Subscription($doc);
    }

    /**
     * Get the active subscription for an entity.
     *
     * @param  string  $entityId  The entity ID
     */
    public function getActiveSubscription(string $entityId): ?Subscription
    {
        $doc = $this->adapter->getActiveSubscription($entityId);

        if ($doc === null) {
            return null;
        }

        return new Subscription($doc);
    }

    /**
     * Cancel a subscription.
     *
     * By default, cancels at the end of the current billing period (sets status
     * to 'canceling'). If atPeriodEnd is false, cancels immediately.
     *
     * @param  string  $subscriptionId  The subscription ID
     * @param  bool  $atPeriodEnd  Whether to cancel at period end (default true)
     *
     * @throws Exception
     */
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        if ($subscription->isTerminal()) {
            throw new Exception('Cannot cancel a subscription that is already terminated');
        }

        if ($atPeriodEnd) {
            $subscription->setStatus(SubscriptionStatus::Canceling);
            $subscription->setCancelAtPeriodEnd(true);
        } else {
            $subscription->setStatus(SubscriptionStatus::Canceled);
            $subscription->setCancelAtPeriodEnd(false);
        }

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionCanceled, $subscription);

        return $subscription;
    }

    /**
     * Renew a subscription for a new billing period.
     *
     * Advances the billing period, resets budget usage, and applies any
     * pending downgrade. The subscription must be in an active-like state.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function renewSubscription(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        $status = $subscription->getStatus();
        if (! \in_array($status, [
            SubscriptionStatus::Active,
            SubscriptionStatus::Canceling,
        ], true)) {
            throw new Exception("Cannot renew subscription in '{$status->value}' status");
        }

        // If canceling, finalize the cancellation
        if ($status === SubscriptionStatus::Canceling) {
            $subscription->setStatus(SubscriptionStatus::Canceled);
            $subscription->setCancelAtPeriodEnd(false);
            $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
            $subscription = new Subscription($doc);
            $this->emit(Event::SubscriptionCanceled, $subscription);

            return $subscription;
        }

        // Apply pending downgrade if exists
        if ($subscription->getPendingChangeType() === ChangeType::Downgrade) {
            $subscription->setPlanId((string) $subscription->getPendingPlanId());
            $subscription->setPendingPlanId(null);
            $subscription->setPendingChangeType(null);
            $subscription->setPendingChangedAt(null);

            $this->emit(Event::SubscriptionDowngraded, $subscription);
        }

        // Advance billing period
        $now = new DateTime();
        $newEnd = (clone $now)->modify('+1 month');
        $subscription->setCurrentPeriodStart($now->format('Y-m-d\TH:i:s.000+00:00'));
        $subscription->setCurrentPeriodEnd($newEnd->format('Y-m-d\TH:i:s.000+00:00'));

        // Reset budget usage
        $subscription->setBudgetUsed(0.0);
        $subscription->setBudgetLimitReached(false);

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionRenewed, $subscription);

        return $subscription;
    }

    // -------------------------------------------------------------------------
    // Plan Changes
    // -------------------------------------------------------------------------

    /**
     * Request an upgrade to a new plan.
     *
     * Upgrades do NOT apply immediately. The subscription stores the pending
     * change and keeps the old plan until payment confirms (Stripe's
     * pending_if_incomplete pattern).
     *
     * @param  string  $subscriptionId  The subscription ID
     * @param  string  $newPlanId  The new plan ID to upgrade to
     * @param  DateTime|null  $expiresAt  Auto-expiry for the upgrade (default 23h)
     *
     * @throws Exception
     */
    public function requestUpgrade(string $subscriptionId, string $newPlanId, ?DateTime $expiresAt = null): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        if (! $subscription->isAccessible()) {
            throw new Exception('Cannot upgrade a subscription that is not accessible');
        }

        if ($subscription->hasPendingChange()) {
            throw new Exception('Subscription already has a pending plan change');
        }

        $now = new DateTime();
        $expiresAt = $expiresAt ?? (clone $now)->modify('+23 hours');

        $subscription->setPendingPlanId($newPlanId);
        $subscription->setPendingChangeType(ChangeType::Upgrade);
        $subscription->setPendingChangedAt($now->format('Y-m-d\TH:i:s.000+00:00'));
        $subscription->setPendingExpiresAt($expiresAt->format('Y-m-d\TH:i:s.000+00:00'));

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionUpgradePending, $subscription);

        return $subscription;
    }

    /**
     * Finalize an upgrade after payment succeeds.
     *
     * Applies the pending plan change and clears pending state.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function finalizeUpgrade(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        if ($subscription->getPendingChangeType() !== ChangeType::Upgrade) {
            throw new Exception('No pending upgrade to finalize');
        }

        $subscription->setPlanId((string) $subscription->getPendingPlanId());
        $subscription->setPendingPlanId(null);
        $subscription->setPendingChangeType(null);
        $subscription->setPendingChangedAt(null);
        $subscription->setPendingExpiresAt(null);
        $subscription->setPendingInvoiceId(null);

        // Ensure active status
        if ($subscription->getStatus() === SubscriptionStatus::Incomplete) {
            $subscription->setStatus(SubscriptionStatus::Active);
        }

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionUpgraded, $subscription);

        return $subscription;
    }

    /**
     * Cancel a pending upgrade.
     *
     * Clears the pending upgrade state. Used when payment fails or the
     * upgrade times out.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function cancelUpgrade(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        if ($subscription->getPendingChangeType() !== ChangeType::Upgrade) {
            throw new Exception('No pending upgrade to cancel');
        }

        // Void the pending invoice if it exists
        $pendingInvoiceId = $subscription->getPendingInvoiceId();
        if ($pendingInvoiceId !== null) {
            try {
                $this->voidInvoice($pendingInvoiceId);
            } catch (Exception) {
                // Invoice may already be voided
            }
        }

        $subscription->setPendingPlanId(null);
        $subscription->setPendingChangeType(null);
        $subscription->setPendingChangedAt(null);
        $subscription->setPendingExpiresAt(null);
        $subscription->setPendingInvoiceId(null);

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionUpgradeFailed, $subscription);

        return $subscription;
    }

    /**
     * Request a downgrade to a new plan.
     *
     * Downgrades are deferred to the end of the current billing cycle.
     * The customer keeps their current plan until the period ends.
     *
     * @param  string  $subscriptionId  The subscription ID
     * @param  string  $newPlanId  The new plan ID to downgrade to
     *
     * @throws Exception
     */
    public function requestDowngrade(string $subscriptionId, string $newPlanId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        if (! $subscription->isAccessible()) {
            throw new Exception('Cannot downgrade a subscription that is not accessible');
        }

        if ($subscription->hasPendingChange()) {
            throw new Exception('Subscription already has a pending plan change');
        }

        $now = new DateTime();

        $subscription->setPendingPlanId($newPlanId);
        $subscription->setPendingChangeType(ChangeType::Downgrade);
        $subscription->setPendingChangedAt($now->format('Y-m-d\TH:i:s.000+00:00'));

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionDowngradeScheduled, $subscription);

        return $subscription;
    }

    /**
     * Cancel a pending downgrade.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function cancelDowngrade(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        if ($subscription->getPendingChangeType() !== ChangeType::Downgrade) {
            throw new Exception('No pending downgrade to cancel');
        }

        $subscription->setPendingPlanId(null);
        $subscription->setPendingChangeType(null);
        $subscription->setPendingChangedAt(null);

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());

        return new Subscription($doc);
    }

    /**
     * Apply a pending downgrade at cycle end.
     *
     * Called by the app during renewal to switch the plan.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function applyPendingDowngrade(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        if ($subscription->getPendingChangeType() !== ChangeType::Downgrade) {
            throw new Exception('No pending downgrade to apply');
        }

        $subscription->setPlanId((string) $subscription->getPendingPlanId());
        $subscription->setPendingPlanId(null);
        $subscription->setPendingChangeType(null);
        $subscription->setPendingChangedAt(null);

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionDowngraded, $subscription);

        return $subscription;
    }

    // -------------------------------------------------------------------------
    // Budget
    // -------------------------------------------------------------------------

    /**
     * Set a spending budget (cap) for a subscription's billing cycle.
     *
     * @param  string  $subscriptionId  The subscription ID
     * @param  float|null  $budget  Dollar cap per cycle (null = unlimited)
     *
     * @throws Exception
     */
    public function setBudget(string $subscriptionId, ?float $budget): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        $subscription->setBudget($budget);

        // Recompute limit reached
        if ($budget !== null && $subscription->getBudgetUsed() >= $budget) {
            $subscription->setBudgetLimitReached(true);
        } else {
            $subscription->setBudgetLimitReached(false);
        }

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());

        return new Subscription($doc);
    }

    /**
     * Update the budget usage for a subscription.
     *
     * The app calls this after each usage aggregation. The library computes
     * budgetLimitReached and emits warning/reached events.
     *
     * @param  string  $subscriptionId  The subscription ID
     * @param  float  $amount  The new total usage amount for the current cycle
     *
     * @throws Exception
     */
    public function updateBudgetUsed(string $subscriptionId, float $amount): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        $previouslyReached = $subscription->getBudgetLimitReached();
        $subscription->setBudgetUsed($amount);

        $budget = $subscription->getBudget();
        if ($budget !== null) {
            $reached = $amount >= $budget;
            $subscription->setBudgetLimitReached($reached);

            if ($reached && ! $previouslyReached) {
                $this->emit(Event::SubscriptionBudgetReached, $subscription);
            } elseif (! $reached && $amount >= $budget * 0.8 && ! $previouslyReached) {
                $this->emit(Event::SubscriptionBudgetWarning, $subscription);
            }
        }

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());

        return new Subscription($doc);
    }

    // -------------------------------------------------------------------------
    // Dunning
    // -------------------------------------------------------------------------

    /**
     * Record a failed payment attempt on a subscription.
     *
     * Increments the failed attempt counter and transitions to 'past_due'.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function recordPaymentFailure(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);
        $now = new DateTime();

        $subscription->setFailedPaymentAttempts($subscription->getFailedPaymentAttempts() + 1);
        $subscription->setLastFailedAt($now->format('Y-m-d\TH:i:s.000+00:00'));

        if ($subscription->getStatus() === SubscriptionStatus::Active) {
            $subscription->setStatus(SubscriptionStatus::PastDue);
        }

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::PaymentFailed, $subscription);

        return $subscription;
    }

    /**
     * Record a successful payment on a subscription.
     *
     * Resets dunning counters and transitions back to 'active'.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function recordPaymentSuccess(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        $subscription->setFailedPaymentAttempts(0);
        $subscription->setNextRetryAt(null);
        $subscription->setLastFailedAt(null);

        $status = $subscription->getStatus();
        if (\in_array($status, [
            SubscriptionStatus::Incomplete,
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Trialing,
        ], true)) {
            $subscription->setStatus(SubscriptionStatus::Active);
        }

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());

        return new Subscription($doc);
    }

    /**
     * Suspend a subscription after max retries are exhausted.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function suspendSubscription(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);

        $subscription->setStatus(SubscriptionStatus::Suspended);

        $doc = $this->adapter->updateSubscription($subscriptionId, $subscription->getDocument());
        $subscription = new Subscription($doc);

        $this->emit(Event::SubscriptionSuspended, $subscription);

        return $subscription;
    }

    // -------------------------------------------------------------------------
    // Invoices
    // -------------------------------------------------------------------------

    /**
     * Create a new invoice.
     *
     * Invoices start in 'draft' status. Line items can be provided at creation
     * or added later via addInvoiceItem().
     *
     * @param  string  $entityId  The entity ID
     * @param  string  $type  The invoice type (e.g., 'subscription', 'wallet_topup')
     * @param  string|null  $subscriptionId  Optional subscription ID
     * @param  array<int, array<string, mixed>>  $items  Optional initial line items
     * @param  string  $currency  Currency code (default 'USD')
     * @param  array<string, mixed>  $metadata  Optional metadata
     *
     * @throws Exception
     */
    public function createInvoice(
        string $entityId,
        string $type,
        ?string $subscriptionId = null,
        array $items = [],
        string $currency = 'USD',
        array $metadata = [],
    ): Invoice {
        $data = [
            '$id' => ID::unique(),
            '$permissions' => [],
            'subscriptionId' => $subscriptionId,
            'referenceInvoiceId' => null,
            'entityId' => $entityId,
            'type' => $type,
            'number' => '',
            'status' => InvoiceStatus::Draft->value,
            'items' => \json_encode($items),
            'subtotal' => 0.0,
            'discountTotal' => 0.0,
            'taxTotal' => 0.0,
            'total' => 0.0,
            'walletDeducted' => 0.0,
            'gatewayCharged' => 0.0,
            'currency' => $currency,
            'dueDate' => null,
            'paidAt' => null,
            'metadata' => \json_encode($metadata),
        ];

        $doc = $this->adapter->createInvoice(new Document($data));

        return new Invoice($doc);
    }

    /**
     * Get an invoice by ID.
     *
     * @param  string  $id  The invoice ID
     *
     * @throws Exception If not found
     */
    public function getInvoice(string $id): Invoice
    {
        $doc = $this->adapter->getInvoice($id);

        if ($doc->isEmpty()) {
            throw new Exception('Invoice not found');
        }

        return new Invoice($doc);
    }

    /**
     * List invoices for an entity.
     *
     * @param  string  $entityId  The entity ID
     * @param  array<string, mixed>  $filters  Optional filters: 'status', 'type', 'subscriptionId'
     * @return array<Invoice>
     */
    public function listInvoices(string $entityId, array $filters = []): array
    {
        $docs = $this->adapter->listInvoices($entityId, $filters);

        return \array_map(fn (Document $doc) => new Invoice($doc), $docs);
    }

    /**
     * Add a line item to a draft invoice.
     *
     * @param  string  $invoiceId  The invoice ID
     * @param  array<string, mixed>  $item  The line item array
     *
     * @throws Exception If invoice is not in draft status
     */
    public function addInvoiceItem(string $invoiceId, array $item): Invoice
    {
        $invoice = $this->getInvoice($invoiceId);

        if ($invoice->isFinalized()) {
            throw new Exception('Cannot add items to a finalized invoice');
        }

        $invoice->addItem($item);
        $doc = $this->adapter->updateInvoice($invoiceId, $invoice->getDocument());

        return new Invoice($doc);
    }

    /**
     * Finalize an invoice.
     *
     * This is the core calculation step:
     * 1. Queries active discounts for the subscription
     * 2. Applies line-level discounts (scope.resources match)
     * 3. Applies invoice-level discounts (scope is null)
     * 4. Decrements discount cyclesRemaining
     * 5. Adds tax line items
     * 6. Computes subtotal, discountTotal, taxTotal, total
     * 7. Generates invoice number
     * 8. Emits 'invoice.finalized'
     *
     * @param  string  $invoiceId  The invoice ID
     * @param  array<string, mixed>  $options  Options: 'taxRate', 'taxDescription', 'taxableTypes' => ['plan', 'usage', 'addon']
     *
     * @throws Exception
     */
    public function finalizeInvoice(string $invoiceId, array $options = []): Invoice
    {
        $invoice = $this->getInvoice($invoiceId);

        if ($invoice->isFinalized()) {
            throw new Exception('Invoice is already finalized');
        }

        $taxRate = (float) ($options['taxRate'] ?? 0.0);
        $taxDescription = (string) ($options['taxDescription'] ?? 'Tax');
        $taxableTypes = (array) ($options['taxableTypes'] ?? [
            Invoice::ITEM_TYPE_PLAN,
            Invoice::ITEM_TYPE_USAGE,
            Invoice::ITEM_TYPE_ADDON,
        ]);

        $items = $invoice->getItems();

        // Step 1: Calculate subtotal from non-discount, non-tax items
        $subtotal = 0.0;
        foreach ($items as $item) {
            $itemType = $item['type'] ?? '';
            if ($itemType !== Invoice::ITEM_TYPE_DISCOUNT && $itemType !== Invoice::ITEM_TYPE_TAX) {
                $subtotal += (float) ($item['amount'] ?? 0);
            }
        }

        // Step 2: Apply discounts from subscription's active discounts
        $discountTotal = 0.0;
        $subscriptionId = $invoice->getSubscriptionId();

        if ($subscriptionId !== null) {
            $discountDocs = $this->adapter->listDiscounts($subscriptionId, ['status' => 'active']);

            foreach ($discountDocs as $discountDoc) {
                $discount = new Discount($discountDoc);

                if ($discount->isLineLevel()) {
                    // Line-level: apply only to matching resource items
                    $resources = $discount->getScopeResources();
                    $matchingAmount = 0.0;

                    foreach ($items as $item) {
                        $itemResource = $item['resource'] ?? null;
                        if ($itemResource !== null && \in_array($itemResource, $resources, true)) {
                            $matchingAmount += (float) ($item['amount'] ?? 0);
                        }
                    }

                    if ($matchingAmount > 0) {
                        $discountAmount = $this->calculateDiscountAmount($discount, $matchingAmount);
                        if ($discountAmount > 0) {
                            $items[] = [
                                'type' => Invoice::ITEM_TYPE_DISCOUNT,
                                'description' => $this->buildDiscountDescription($discount),
                                'resource' => \implode(', ', $resources),
                                'quantity' => null,
                                'unit' => null,
                                'unitPrice' => null,
                                'amount' => -$discountAmount,
                                'discountId' => $discount->getId(),
                                'rate' => null,
                                'metadata' => '{}',
                            ];
                            $discountTotal += $discountAmount;
                        }
                    }
                } else {
                    // Invoice-level: apply to subtotal
                    $discountAmount = $this->calculateDiscountAmount($discount, $subtotal);
                    if ($discountAmount > 0) {
                        $items[] = [
                            'type' => Invoice::ITEM_TYPE_DISCOUNT,
                            'description' => $this->buildDiscountDescription($discount),
                            'resource' => null,
                            'quantity' => null,
                            'unit' => null,
                            'unitPrice' => null,
                            'amount' => -$discountAmount,
                            'discountId' => $discount->getId(),
                            'rate' => null,
                            'metadata' => '{}',
                        ];
                        $discountTotal += $discountAmount;
                    }
                }

                // Decrement cycles remaining
                $cyclesRemaining = $discount->getCyclesRemaining();
                if ($cyclesRemaining !== null) {
                    $newRemaining = $cyclesRemaining - 1;
                    $discount->setCyclesRemaining($newRemaining);

                    if ($newRemaining <= 0) {
                        $discount->setStatus(DiscountStatus::Exhausted);
                        $discount->setExhaustedAt((new DateTime())->format('Y-m-d\TH:i:s.000+00:00'));
                        $this->emit(Event::DiscountExhausted, $discount);
                    }

                    $this->adapter->updateDiscount($discount->getId(), $discount->getDocument());
                }
            }
        }

        // Step 3: Add tax line items
        $taxTotal = 0.0;
        if ($taxRate > 0) {
            $taxableAmount = 0.0;
            foreach ($items as $item) {
                $itemType = $item['type'] ?? '';
                if (\in_array($itemType, $taxableTypes, true)) {
                    $taxableAmount += (float) ($item['amount'] ?? 0);
                }
            }

            // Apply discounts to taxable amount
            $taxableAmount = \max(0.0, $taxableAmount - $discountTotal);
            $taxAmount = Credit::calculateTax($taxableAmount, $taxRate);

            if ($taxAmount > 0) {
                $items[] = [
                    'type' => Invoice::ITEM_TYPE_TAX,
                    'description' => $taxDescription.' ('.\number_format($taxRate, 1).'%)',
                    'resource' => null,
                    'quantity' => null,
                    'unit' => null,
                    'unitPrice' => null,
                    'amount' => $taxAmount,
                    'discountId' => null,
                    'rate' => $taxRate,
                    'metadata' => '{}',
                ];
                $taxTotal = $taxAmount;
            }
        }

        // Step 4: Compute totals
        $total = \round($subtotal - $discountTotal + $taxTotal, 2);

        // Step 5: Generate invoice number
        $sequence = $this->adapter->getNextInvoiceSequence();
        $number = $this->generateInvoiceNumber($sequence);

        // Step 6: Update invoice
        $invoice->setItems($items);
        $invoice->setSubtotal(\round($subtotal, 2));
        $invoice->setDiscountTotal(\round($discountTotal, 2));
        $invoice->setTaxTotal(\round($taxTotal, 2));
        $invoice->setTotal($total);
        $invoice->setNumber($number);
        $invoice->setStatus(InvoiceStatus::Finalized);

        $doc = $this->adapter->updateInvoice($invoiceId, $invoice->getDocument());
        $invoice = new Invoice($doc);

        $this->emit(Event::InvoiceFinalized, $invoice);

        return $invoice;
    }

    /**
     * Mark an invoice as paid.
     *
     * @param  string  $invoiceId  The invoice ID
     * @param  string  $paymentId  The payment/transaction ID
     *
     * @throws Exception
     */
    public function markInvoicePaid(string $invoiceId, string $paymentId): Invoice
    {
        $invoice = $this->getInvoice($invoiceId);

        if ($invoice->getStatus() !== InvoiceStatus::Finalized) {
            throw new Exception('Only finalized invoices can be marked as paid');
        }

        $invoice->setStatus(InvoiceStatus::Paid);
        $invoice->setPaidAt((new DateTime())->format('Y-m-d\TH:i:s.000+00:00'));

        $doc = $this->adapter->updateInvoice($invoiceId, $invoice->getDocument());
        $invoice = new Invoice($doc);

        $this->emit(Event::InvoicePaid, $invoice);

        return $invoice;
    }

    /**
     * Mark an invoice as failed.
     *
     * @param  string  $invoiceId  The invoice ID
     *
     * @throws Exception
     */
    public function markInvoiceFailed(string $invoiceId): Invoice
    {
        $invoice = $this->getInvoice($invoiceId);

        if ($invoice->getStatus() !== InvoiceStatus::Finalized) {
            throw new Exception('Only finalized invoices can be marked as failed');
        }

        $invoice->setStatus(InvoiceStatus::Failed);

        $doc = $this->adapter->updateInvoice($invoiceId, $invoice->getDocument());
        $invoice = new Invoice($doc);

        $this->emit(Event::InvoiceFailed, $invoice);

        return $invoice;
    }

    /**
     * Void an invoice.
     *
     * @param  string  $invoiceId  The invoice ID
     *
     * @throws Exception
     */
    public function voidInvoice(string $invoiceId): Invoice
    {
        $invoice = $this->getInvoice($invoiceId);

        $status = $invoice->getStatus();
        if ($status === InvoiceStatus::Paid || $status === InvoiceStatus::Voided) {
            throw new Exception("Cannot void an invoice that is '{$status->value}'");
        }

        $invoice->setStatus(InvoiceStatus::Voided);

        $doc = $this->adapter->updateInvoice($invoiceId, $invoice->getDocument());
        $invoice = new Invoice($doc);

        $this->emit(Event::InvoiceVoided, $invoice);

        return $invoice;
    }

    /**
     * Create a credit note referencing an existing invoice.
     *
     * A credit note is an invoice with type 'credit_note' and a reference to
     * the original invoice. Line items should have negative amounts.
     *
     * @param  string  $referenceInvoiceId  The original invoice ID
     * @param  array<int, array<string, mixed>>  $refundItems  The refund line items (amounts should be negative)
     *
     * @throws Exception
     */
    public function createCreditNote(string $referenceInvoiceId, array $refundItems): Invoice
    {
        $originalInvoice = $this->getInvoice($referenceInvoiceId);

        $data = [
            '$id' => ID::unique(),
            '$permissions' => [],
            'subscriptionId' => $originalInvoice->getSubscriptionId(),
            'referenceInvoiceId' => $referenceInvoiceId,
            'entityId' => $originalInvoice->getEntityId(),
            'type' => 'credit_note',
            'number' => '',
            'status' => InvoiceStatus::Draft->value,
            'items' => \json_encode($refundItems),
            'subtotal' => 0.0,
            'discountTotal' => 0.0,
            'taxTotal' => 0.0,
            'total' => 0.0,
            'walletDeducted' => 0.0,
            'gatewayCharged' => 0.0,
            'currency' => $originalInvoice->getCurrency(),
            'dueDate' => null,
            'paidAt' => null,
            'metadata' => '{}',
        ];

        $doc = $this->adapter->createInvoice(new Document($data));

        return new Invoice($doc);
    }

    // -------------------------------------------------------------------------
    // Coupons
    // -------------------------------------------------------------------------

    /**
     * Create a new coupon.
     *
     * @param  string  $code  The user-facing coupon code
     * @param  string  $type  'fixed' or 'percentage'
     * @param  float  $value  Dollar amount for fixed, percentage for percentage
     * @param  string  $duration  'once', 'repeating', or 'forever'
     * @param  int|null  $durationInCycles  Number of cycles for 'repeating' duration
     * @param  array<string, mixed>  $options  Additional options: 'currency', 'maxRedemptions', 'expiresAt', 'scope', 'metadata'
     *
     * @throws Exception
     */
    public function createCoupon(
        string $code,
        string $type,
        float $value,
        string $duration,
        ?int $durationInCycles = null,
        array $options = [],
    ): Coupon {
        $data = [
            '$id' => ID::unique(),
            '$permissions' => [],
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'currency' => $options['currency'] ?? null,
            'duration' => $duration,
            'durationInCycles' => $durationInCycles,
            'maxRedemptions' => $options['maxRedemptions'] ?? null,
            'timesRedeemed' => 0,
            'expiresAt' => $options['expiresAt'] ?? null,
            'scope' => isset($options['scope']) ? \json_encode($options['scope']) : null,
            'active' => true,
            'metadata' => \json_encode($options['metadata'] ?? []),
        ];

        $doc = $this->adapter->createCoupon(new Document($data));

        return new Coupon($doc);
    }

    /**
     * Get a coupon by its user-facing code.
     *
     * @param  string  $code  The coupon code
     *
     * @throws Exception If not found
     */
    public function getCoupon(string $code): Coupon
    {
        $doc = $this->adapter->getCouponByCode($code);

        if ($doc->isEmpty()) {
            throw new Exception('Coupon not found');
        }

        return new Coupon($doc);
    }

    /**
     * List coupons with optional filters.
     *
     * @param  array<string, mixed>  $filters  Optional filters: 'active', 'type', 'duration'
     * @return array<Coupon>
     */
    public function listCoupons(array $filters = []): array
    {
        $docs = $this->adapter->listCoupons($filters);

        return \array_map(fn (Document $doc) => new Coupon($doc), $docs);
    }

    /**
     * Deactivate a coupon so it can no longer be redeemed.
     *
     * @param  string  $code  The coupon code
     *
     * @throws Exception If not found
     */
    public function deactivateCoupon(string $code): Coupon
    {
        $coupon = $this->getCoupon($code);
        $coupon->setActive(false);

        $doc = $this->adapter->updateCoupon($coupon->getId(), $coupon->getDocument());

        return new Coupon($doc);
    }

    // -------------------------------------------------------------------------
    // Discounts
    // -------------------------------------------------------------------------

    /**
     * Apply a coupon to a subscription, creating a discount.
     *
     * For fixed coupons, the value is immediately credited to the entity's wallet.
     * For percentage coupons, a discount record is created that will be applied
     * during invoice finalization.
     *
     * @param  string  $couponCode  The coupon code to apply
     * @param  string  $subscriptionId  The subscription ID
     * @param  string  $entityId  The entity ID
     *
     * @throws Exception
     */
    public function applyDiscount(string $couponCode, string $subscriptionId, string $entityId): Discount
    {
        $coupon = $this->getCoupon($couponCode);

        if (! $coupon->isRedeemable()) {
            throw new Exception('Coupon is not redeemable');
        }

        $now = new DateTime();

        // Determine cycles
        $cyclesTotal = null;
        $cyclesRemaining = null;
        if ($coupon->getDuration() === CouponDuration::Once) {
            $cyclesTotal = 1;
            $cyclesRemaining = 1;
        } elseif ($coupon->getDuration() === CouponDuration::Repeating) {
            $cyclesTotal = $coupon->getDurationInCycles();
            $cyclesRemaining = $coupon->getDurationInCycles();
        }

        $data = [
            '$id' => ID::unique(),
            '$permissions' => [],
            'couponId' => $coupon->getId(),
            'subscriptionId' => $subscriptionId,
            'entityId' => $entityId,
            'type' => $coupon->getType()->value,
            'value' => $coupon->getValue(),
            'duration' => $coupon->getDuration()->value,
            'scope' => $coupon->getScope() !== null ? \json_encode($coupon->getScope()) : null,
            'cyclesTotal' => $cyclesTotal,
            'cyclesRemaining' => $cyclesRemaining,
            'status' => DiscountStatus::Active->value,
            'appliedAt' => $now->format('Y-m-d\TH:i:s.000+00:00'),
            'exhaustedAt' => null,
            'cancelledAt' => null,
            'metadata' => '{}',
        ];

        $doc = $this->adapter->createDiscount(new Document($data));
        $discount = new Discount($doc);

        // Increment coupon redemption counter
        $coupon->setTimesRedeemed($coupon->getTimesRedeemed() + 1);
        $this->adapter->updateCoupon($coupon->getId(), $coupon->getDocument());

        $this->emit(Event::DiscountApplied, $discount);
        $this->emit(Event::CouponRedeemed, $coupon);

        return $discount;
    }

    /**
     * Get a discount by ID.
     *
     * @param  string  $id  The discount ID
     *
     * @throws Exception If not found
     */
    public function getDiscount(string $id): Discount
    {
        $doc = $this->adapter->getDiscount($id);

        if ($doc->isEmpty()) {
            throw new Exception('Discount not found');
        }

        return new Discount($doc);
    }

    /**
     * List active discounts for a subscription.
     *
     * @param  string  $subscriptionId  The subscription ID
     * @return array<Discount>
     */
    public function listActiveDiscounts(string $subscriptionId): array
    {
        $docs = $this->adapter->listDiscounts($subscriptionId, ['status' => 'active']);

        return \array_map(fn (Document $doc) => new Discount($doc), $docs);
    }

    /**
     * Cancel a discount.
     *
     * @param  string  $id  The discount ID
     *
     * @throws Exception
     */
    public function cancelDiscount(string $id): Discount
    {
        $discount = $this->getDiscount($id);

        if (! $discount->isActive()) {
            throw new Exception('Discount is not active');
        }

        $discount->setStatus(DiscountStatus::Cancelled);
        $discount->setCancelledAt((new DateTime())->format('Y-m-d\TH:i:s.000+00:00'));

        $doc = $this->adapter->updateDiscount($id, $discount->getDocument());
        $discount = new Discount($doc);

        $this->emit(Event::DiscountCancelled, $discount);

        return $discount;
    }

    // -------------------------------------------------------------------------
    // Wallet
    // -------------------------------------------------------------------------

    /**
     * Get or create a wallet for an entity.
     *
     * @param  string  $entityId  The entity ID
     * @param  string  $currency  Currency code (default 'USD')
     *
     * @throws Exception
     */
    public function getOrCreateWallet(string $entityId, string $currency = 'USD'): Wallet
    {
        $doc = $this->adapter->getWalletByEntity($entityId);

        if ($doc !== null) {
            return new Wallet($doc);
        }

        $data = [
            '$id' => ID::unique(),
            '$permissions' => [],
            'entityId' => $entityId,
            'balance' => 0.0,
            'currency' => $currency,
            'metadata' => '{}',
        ];

        $doc = $this->adapter->createWallet(new Document($data));

        return new Wallet($doc);
    }

    /**
     * Get the wallet balance for an entity.
     *
     * @param  string  $entityId  The entity ID
     * @return float The wallet balance (0.0 if no wallet exists)
     */
    public function getWalletBalance(string $entityId): float
    {
        $doc = $this->adapter->getWalletByEntity($entityId);

        if ($doc === null) {
            return 0.0;
        }

        return (new Wallet($doc))->getBalance();
    }

    /**
     * Add funds to an entity's wallet.
     *
     * @param  string  $entityId  The entity ID
     * @param  string  $invoiceId  The receipt invoice ID (wallet topups generate an invoice)
     * @param  float  $amount  The amount to add
     * @param  string  $description  Optional description
     *
     * @throws Exception
     */
    public function addFunds(string $entityId, string $invoiceId, float $amount, string $description = ''): Transaction
    {
        if ($amount <= 0) {
            throw new Exception('Amount must be positive');
        }

        $wallet = $this->getOrCreateWallet($entityId);
        $wallet->setBalance($wallet->getBalance() + $amount);
        $this->adapter->updateWallet($wallet->getId(), $wallet->getDocument());

        $transaction = $this->createTransaction(
            $entityId,
            $invoiceId,
            TransactionType::WalletTopup->value,
            $amount,
            $wallet->getId(),
        );

        $this->emit(Event::WalletFunded, $wallet);

        return $transaction;
    }

    /**
     * Deduct funds from an entity's wallet.
     *
     * @param  string  $entityId  The entity ID
     * @param  string  $invoiceId  The invoice ID being paid
     * @param  float  $amount  The amount to deduct
     * @param  string  $description  Optional description
     *
     * @throws Exception
     */
    public function deductFunds(string $entityId, string $invoiceId, float $amount, string $description = ''): Transaction
    {
        if ($amount <= 0) {
            throw new Exception('Amount must be positive');
        }

        $wallet = $this->getOrCreateWallet($entityId);

        if (! $wallet->hasSufficientBalance($amount)) {
            throw new Exception('Insufficient wallet balance');
        }

        $wallet->setBalance(\round($wallet->getBalance() - $amount, 2));
        $this->adapter->updateWallet($wallet->getId(), $wallet->getDocument());

        $transaction = $this->createTransaction(
            $entityId,
            $invoiceId,
            TransactionType::WalletDeduction->value,
            $amount,
            $wallet->getId(),
        );

        $this->emit(Event::WalletDeducted, $wallet);

        return $transaction;
    }

    /**
     * Refund funds to an entity's wallet.
     *
     * @param  string  $entityId  The entity ID
     * @param  string  $invoiceId  The invoice ID being refunded
     * @param  float  $amount  The amount to refund
     * @param  string  $description  Optional description
     *
     * @throws Exception
     */
    public function refundToWallet(string $entityId, string $invoiceId, float $amount, string $description = ''): Transaction
    {
        if ($amount <= 0) {
            throw new Exception('Amount must be positive');
        }

        $wallet = $this->getOrCreateWallet($entityId);
        $wallet->setBalance(\round($wallet->getBalance() + $amount, 2));
        $this->adapter->updateWallet($wallet->getId(), $wallet->getDocument());

        $transaction = $this->createTransaction(
            $entityId,
            $invoiceId,
            TransactionType::WalletRefund->value,
            $amount,
            $wallet->getId(),
        );

        return $transaction;
    }

    // -------------------------------------------------------------------------
    // Transactions
    // -------------------------------------------------------------------------

    /**
     * Create a transaction record.
     *
     * @param  string  $entityId  The entity ID
     * @param  string  $invoiceId  The invoice ID
     * @param  string  $type  The transaction type (see Transaction::TYPE_*)
     * @param  float  $amount  The transaction amount
     * @param  string|null  $walletId  Optional wallet ID for wallet operations
     * @param  string|null  $providerPaymentId  Optional gateway payment ID
     * @param  string  $description  Optional description
     *
     * @throws Exception
     */
    public function createTransaction(
        string $entityId,
        string $invoiceId,
        string $type,
        float $amount,
        ?string $walletId = null,
        ?string $providerPaymentId = null,
        string $description = '',
    ): Transaction {
        $data = [
            '$id' => ID::unique(),
            '$permissions' => [],
            'entityId' => $entityId,
            'invoiceId' => $invoiceId,
            'type' => $type,
            'amount' => $amount,
            'status' => TransactionStatus::Succeeded->value,
            'walletId' => $walletId,
            'providerPaymentId' => $providerPaymentId,
            'description' => $description,
            'metadata' => '{}',
        ];

        $doc = $this->adapter->createTransaction(new Document($data));
        $transaction = new Transaction($doc);

        $this->emit(Event::TransactionCreated, $transaction);

        return $transaction;
    }

    /**
     * Get a transaction by ID.
     *
     * @param  string  $id  The transaction ID
     *
     * @throws Exception If not found
     */
    public function getTransaction(string $id): Transaction
    {
        $doc = $this->adapter->getTransaction($id);

        if ($doc->isEmpty()) {
            throw new Exception('Transaction not found');
        }

        return new Transaction($doc);
    }

    /**
     * List transactions for an entity.
     *
     * @param  string  $entityId  The entity ID
     * @param  array<string, mixed>  $filters  Optional filters: 'type', 'status', 'walletId'
     * @return array<Transaction>
     */
    public function listTransactions(string $entityId, array $filters = []): array
    {
        $docs = $this->adapter->listTransactions($entityId, $filters);

        return \array_map(fn (Document $doc) => new Transaction($doc), $docs);
    }

    /**
     * List all transactions for a specific invoice.
     *
     * @param  string  $invoiceId  The invoice ID
     * @return array<Transaction>
     */
    public function listInvoiceTransactions(string $invoiceId): array
    {
        $docs = $this->adapter->listInvoiceTransactions($invoiceId);

        return \array_map(fn (Document $doc) => new Transaction($doc), $docs);
    }

    // -------------------------------------------------------------------------
    // Period & Proration
    // -------------------------------------------------------------------------

    /**
     * Get the current billing period for a subscription.
     *
     * @param  string  $subscriptionId  The subscription ID
     *
     * @throws Exception
     */
    public function getCurrentPeriod(string $subscriptionId): Period
    {
        $subscription = $this->getSubscription($subscriptionId);

        return $subscription->getCurrentPeriod();
    }

    /**
     * Calculate the proration amount for a plan change.
     *
     * Returns the prorated charge for switching to a new plan mid-cycle.
     *
     * @param  string  $subscriptionId  The subscription ID
     * @param  string  $newPlanId  The new plan ID (for reference, not used in calculation)
     * @param  float  $newPrice  The new plan's full-cycle price
     * @return float The prorated amount
     *
     * @throws Exception
     */
    public function calculateProration(string $subscriptionId, string $newPlanId, float $newPrice): float
    {
        $subscription = $this->getSubscription($subscriptionId);
        $period = $subscription->getCurrentPeriod();

        $totalDays = $period->getDays();
        if ($totalDays <= 0) {
            return $newPrice;
        }

        $now = new DateTime();
        $daysRemaining = (int) $now->diff($period->getEnd())->days;

        return Credit::calculateProration($newPrice, $daysRemaining, $totalDays);
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Calculate the discount amount for a given discount and base amount.
     *
     * @param  Discount  $discount  The discount to apply
     * @param  float  $baseAmount  The amount to discount
     * @return float The discount amount (positive value)
     */
    private function calculateDiscountAmount(Discount $discount, float $baseAmount): float
    {
        if ($discount->isPercentage()) {
            return Credit::calculatePercentageDiscountAmount($baseAmount, $discount->getValue());
        }

        // Fixed discount: cap at the base amount
        return \min($discount->getValue(), $baseAmount);
    }

    /**
     * Build a human-readable description for a discount line item.
     *
     * @param  Discount  $discount  The discount
     */
    private function buildDiscountDescription(Discount $discount): string
    {
        if ($discount->isPercentage()) {
            return \number_format($discount->getValue(), 0).'% discount';
        }

        return '$'.\number_format($discount->getValue(), 2).' discount';
    }

    /**
     * Generate an invoice number from the configured format.
     *
     * @param  int  $sequence  The sequence number
     */
    private function generateInvoiceNumber(int $sequence): string
    {
        /** @var string $format */
        $format = $this->options['invoiceNumberFormat'];
        $year = (new DateTime())->format('Y');

        $number = \str_replace('{year}', $year, $format);
        $number = \str_replace('{sequence}', \str_pad((string) $sequence, 5, '0', \STR_PAD_LEFT), $number);

        return (string) $number;
    }
}
