<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use Utopia\Billing\Adapter;
use Utopia\Database\Document;

/**
 * In-memory adapter for unit testing the Billing facade.
 *
 * Stores documents in plain arrays — no database needed.
 */
class InMemoryAdapter extends Adapter
{
    /** @var array<string, Document> */
    private array $subscriptions = [];

    /** @var array<string, Document> */
    private array $invoices = [];

    /** @var array<string, Document> */
    private array $coupons = [];

    /** @var array<string, Document> */
    private array $discounts = [];

    /** @var array<string, Document> */
    private array $wallets = [];

    /** @var array<string, Document> */
    private array $transactions = [];

    private int $invoiceSequence = 0;

    public function setup(): void
    {
    }

    // --- Subscriptions ---

    public function createSubscription(Document $subscription): Document
    {
        $this->subscriptions[$subscription->getId()] = $subscription;

        return $subscription;
    }

    public function getSubscription(string $id): Document
    {
        return $this->subscriptions[$id] ?? new Document();
    }

    public function getActiveSubscription(string $entityId): ?Document
    {
        $active = ['active', 'trialing', 'past_due', 'canceling', 'incomplete'];
        foreach ($this->subscriptions as $doc) {
            if ($doc->getAttribute('entityId') === $entityId
                && \in_array($doc->getAttribute('status'), $active, true)) {
                return $doc;
            }
        }

        return null;
    }

    public function updateSubscription(string $id, Document $subscription): Document
    {
        $this->subscriptions[$id] = $subscription;

        return $subscription;
    }

    public function deleteSubscription(string $id): bool
    {
        unset($this->subscriptions[$id]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listSubscriptions(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $results = [];
        foreach ($this->subscriptions as $doc) {
            if ($doc->getAttribute('entityId') === $entityId) {
                $results[] = $doc;
            }
        }

        return \array_slice($results, $offset, $limit);
    }

    // --- Invoices ---

    public function createInvoice(Document $invoice): Document
    {
        $this->invoices[$invoice->getId()] = $invoice;

        return $invoice;
    }

    public function getInvoice(string $id): Document
    {
        return $this->invoices[$id] ?? new Document();
    }

    public function updateInvoice(string $id, Document $invoice): Document
    {
        $this->invoices[$id] = $invoice;

        return $invoice;
    }

    public function deleteInvoice(string $id): bool
    {
        unset($this->invoices[$id]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listInvoices(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $results = [];
        foreach ($this->invoices as $doc) {
            if ($doc->getAttribute('entityId') === $entityId) {
                if (isset($filters['status']) && $doc->getAttribute('status') !== $filters['status']) {
                    continue;
                }
                if (isset($filters['type']) && $doc->getAttribute('type') !== $filters['type']) {
                    continue;
                }
                $results[] = $doc;
            }
        }

        return \array_slice($results, $offset, $limit);
    }

    public function getNextInvoiceSequence(): int
    {
        return ++$this->invoiceSequence;
    }

    // --- Coupons ---

    public function createCoupon(Document $coupon): Document
    {
        $this->coupons[$coupon->getId()] = $coupon;

        return $coupon;
    }

    public function getCoupon(string $id): Document
    {
        return $this->coupons[$id] ?? new Document();
    }

    public function getCouponByCode(string $code): Document
    {
        foreach ($this->coupons as $doc) {
            if ($doc->getAttribute('code') === $code) {
                return $doc;
            }
        }

        return new Document();
    }

    public function updateCoupon(string $id, Document $coupon): Document
    {
        $this->coupons[$id] = $coupon;

        return $coupon;
    }

    public function deleteCoupon(string $id): bool
    {
        unset($this->coupons[$id]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listCoupons(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $results = \array_values($this->coupons);

        return \array_slice($results, $offset, $limit);
    }

    // --- Discounts ---

    public function createDiscount(Document $discount): Document
    {
        $this->discounts[$discount->getId()] = $discount;

        return $discount;
    }

    public function getDiscount(string $id): Document
    {
        return $this->discounts[$id] ?? new Document();
    }

    public function updateDiscount(string $id, Document $discount): Document
    {
        $this->discounts[$id] = $discount;

        return $discount;
    }

    public function deleteDiscount(string $id): bool
    {
        unset($this->discounts[$id]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listDiscounts(string $subscriptionId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $results = [];
        foreach ($this->discounts as $doc) {
            if ($doc->getAttribute('subscriptionId') === $subscriptionId) {
                if (isset($filters['status']) && $doc->getAttribute('status') !== $filters['status']) {
                    continue;
                }
                $results[] = $doc;
            }
        }

        return \array_slice($results, $offset, $limit);
    }

    // --- Wallets ---

    public function createWallet(Document $wallet): Document
    {
        $this->wallets[$wallet->getId()] = $wallet;

        return $wallet;
    }

    public function getWallet(string $id): Document
    {
        return $this->wallets[$id] ?? new Document();
    }

    public function getWalletByEntity(string $entityId): ?Document
    {
        foreach ($this->wallets as $doc) {
            if ($doc->getAttribute('entityId') === $entityId) {
                return $doc;
            }
        }

        return null;
    }

    public function updateWallet(string $id, Document $wallet): Document
    {
        $this->wallets[$id] = $wallet;

        return $wallet;
    }

    // --- Transactions ---

    public function createTransaction(Document $transaction): Document
    {
        $this->transactions[$transaction->getId()] = $transaction;

        return $transaction;
    }

    public function getTransaction(string $id): Document
    {
        return $this->transactions[$id] ?? new Document();
    }

    public function updateTransaction(string $id, Document $transaction): Document
    {
        $this->transactions[$id] = $transaction;

        return $transaction;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listTransactions(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $results = [];
        foreach ($this->transactions as $doc) {
            if ($doc->getAttribute('entityId') === $entityId) {
                if (isset($filters['type']) && $doc->getAttribute('type') !== $filters['type']) {
                    continue;
                }
                $results[] = $doc;
            }
        }

        return \array_slice($results, $offset, $limit);
    }

    public function listInvoiceTransactions(string $invoiceId, int $limit = 25, int $offset = 0): array
    {
        $results = [];
        foreach ($this->transactions as $doc) {
            if ($doc->getAttribute('invoiceId') === $invoiceId) {
                $results[] = $doc;
            }
        }

        return \array_slice($results, $offset, $limit);
    }
}
