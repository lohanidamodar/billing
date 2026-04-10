<?php

declare(strict_types=1);

namespace Utopia\Billing\Adapter;

use Utopia\Billing\Adapter;
use Utopia\Billing\Exception;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * Database Adapter
 *
 * Concrete adapter implementation using utopia-php/database for persistence.
 * Creates and manages six collections: subscriptions, invoices, coupons,
 * discounts, wallets, and transactions.
 *
 * All operations bypass authorization checks via `$authorization->skip()`
 * to allow internal billing operations regardless of the current user context.
 */
class Database extends Adapter
{
    /**
     * Collection name constants.
     */
    private const COLLECTION_SUBSCRIPTIONS = 'subscriptions';

    private const COLLECTION_INVOICES = 'invoices';

    private const COLLECTION_COUPONS = 'coupons';

    private const COLLECTION_DISCOUNTS = 'discounts';

    private const COLLECTION_WALLETS = 'wallets';

    private const COLLECTION_TRANSACTIONS = 'transactions';

    /**
     * Database adapter constructor.
     *
     * @param  UtopiaDatabase  $db  The utopia-php/database instance
     */
    public function __construct(protected UtopiaDatabase $db)
    {
    }

    /**
     * Set up all billing collections, attributes, and indexes.
     *
     * Creates six collections: subscriptions, invoices, coupons, discounts,
     * wallets, and transactions. This method is idempotent — calling it on
     * an already-initialized database is a no-op.
     *
     *
     * @throws Exception If the database does not exist
     */
    public function setup(): void
    {
        $db = $this->db;
        $authorization = $db->getAuthorization();

        $authorization->skip(function () use ($db) {
            try {
                $exists = $db->exists();
            } catch (\Throwable) {
                $exists = false;
            }

            if (! $exists) {
                throw new Exception('Database not ready');
            }

            $this->createSubscriptionsCollection($db);
            $this->createInvoicesCollection($db);
            $this->createCouponsCollection($db);
            $this->createDiscountsCollection($db);
            $this->createWalletsCollection($db);
            $this->createTransactionsCollection($db);
        });
    }

    // -------------------------------------------------------------------------
    // Subscriptions
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function createSubscription(Document $subscription): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->createDocument(self::COLLECTION_SUBSCRIPTIONS, $subscription)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getSubscription(string $id): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->getDocument(self::COLLECTION_SUBSCRIPTIONS, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveSubscription(string $entityId): ?Document
    {
        $authorization = $this->db->getAuthorization();

        $activeStatuses = [
            'active',
            'trialing',
            'past_due',
            'canceling',
            'incomplete',
        ];

        /** @var Document|null $doc */
        $doc = $authorization->skip(function () use ($entityId, $activeStatuses) {
            $results = $this->db->find(self::COLLECTION_SUBSCRIPTIONS, [
                Query::equal('entityId', [$entityId]),
                Query::equal('status', $activeStatuses),
                Query::limit(1),
            ]);

            return $results[0] ?? null;
        });

        return $doc;
    }

    /**
     * {@inheritDoc}
     */
    public function updateSubscription(string $id, Document $subscription): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->updateDocument(self::COLLECTION_SUBSCRIPTIONS, $id, $subscription)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function deleteSubscription(string $id): bool
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->deleteDocument(self::COLLECTION_SUBSCRIPTIONS, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listSubscriptions(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(function () use ($entityId, $filters, $limit, $offset) {
            $queries = [
                Query::equal('entityId', [$entityId]),
                Query::limit($limit),
                Query::offset($offset),
            ];

            if (isset($filters['status'])) {
                $queries[] = Query::equal('status', \is_array($filters['status']) ? $filters['status'] : [$filters['status']]);
            }

            if (isset($filters['planId'])) {
                $queries[] = Query::equal('planId', \is_array($filters['planId']) ? $filters['planId'] : [$filters['planId']]);
            }

            return $this->db->find(self::COLLECTION_SUBSCRIPTIONS, $queries);
        });
    }

    // -------------------------------------------------------------------------
    // Invoices
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function createInvoice(Document $invoice): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->createDocument(self::COLLECTION_INVOICES, $invoice)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getInvoice(string $id): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->getDocument(self::COLLECTION_INVOICES, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function updateInvoice(string $id, Document $invoice): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->updateDocument(self::COLLECTION_INVOICES, $id, $invoice)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function deleteInvoice(string $id): bool
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->deleteDocument(self::COLLECTION_INVOICES, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listInvoices(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(function () use ($entityId, $filters, $limit, $offset) {
            $queries = [
                Query::equal('entityId', [$entityId]),
                Query::limit($limit),
                Query::offset($offset),
            ];

            if (isset($filters['status'])) {
                $queries[] = Query::equal('status', \is_array($filters['status']) ? $filters['status'] : [$filters['status']]);
            }

            if (isset($filters['type'])) {
                $queries[] = Query::equal('type', \is_array($filters['type']) ? $filters['type'] : [$filters['type']]);
            }

            if (isset($filters['subscriptionId'])) {
                $queries[] = Query::equal('subscriptionId', \is_array($filters['subscriptionId']) ? $filters['subscriptionId'] : [$filters['subscriptionId']]);
            }

            return $this->db->find(self::COLLECTION_INVOICES, $queries);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getNextInvoiceSequence(): int
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(function () {
            $count = $this->db->count(self::COLLECTION_INVOICES);

            return $count + 1;
        });
    }

    // -------------------------------------------------------------------------
    // Coupons
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function createCoupon(Document $coupon): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->createDocument(self::COLLECTION_COUPONS, $coupon)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getCoupon(string $id): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->getDocument(self::COLLECTION_COUPONS, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getCouponByCode(string $code): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(function () use ($code) {
            $results = $this->db->find(self::COLLECTION_COUPONS, [
                Query::equal('code', [$code]),
                Query::limit(1),
            ]);

            if (empty($results)) {
                return new Document();
            }

            return $results[0];
        });
    }

    /**
     * {@inheritDoc}
     */
    public function updateCoupon(string $id, Document $coupon): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->updateDocument(self::COLLECTION_COUPONS, $id, $coupon)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function deleteCoupon(string $id): bool
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->deleteDocument(self::COLLECTION_COUPONS, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listCoupons(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(function () use ($filters, $limit, $offset) {
            $queries = [
                Query::limit($limit),
                Query::offset($offset),
            ];

            if (isset($filters['active'])) {
                $queries[] = Query::equal('active', [$filters['active']]);
            }

            if (isset($filters['type'])) {
                $queries[] = Query::equal('type', \is_array($filters['type']) ? $filters['type'] : [$filters['type']]);
            }

            if (isset($filters['duration'])) {
                $queries[] = Query::equal('duration', \is_array($filters['duration']) ? $filters['duration'] : [$filters['duration']]);
            }

            return $this->db->find(self::COLLECTION_COUPONS, $queries);
        });
    }

    // -------------------------------------------------------------------------
    // Discounts
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function createDiscount(Document $discount): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->createDocument(self::COLLECTION_DISCOUNTS, $discount)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getDiscount(string $id): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->getDocument(self::COLLECTION_DISCOUNTS, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function updateDiscount(string $id, Document $discount): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->updateDocument(self::COLLECTION_DISCOUNTS, $id, $discount)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDiscount(string $id): bool
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->deleteDocument(self::COLLECTION_DISCOUNTS, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listDiscounts(string $subscriptionId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(function () use ($subscriptionId, $filters, $limit, $offset) {
            $queries = [
                Query::equal('subscriptionId', [$subscriptionId]),
                Query::limit($limit),
                Query::offset($offset),
            ];

            if (isset($filters['status'])) {
                $queries[] = Query::equal('status', \is_array($filters['status']) ? $filters['status'] : [$filters['status']]);
            }

            if (isset($filters['couponId'])) {
                $queries[] = Query::equal('couponId', \is_array($filters['couponId']) ? $filters['couponId'] : [$filters['couponId']]);
            }

            if (isset($filters['entityId'])) {
                $queries[] = Query::equal('entityId', \is_array($filters['entityId']) ? $filters['entityId'] : [$filters['entityId']]);
            }

            return $this->db->find(self::COLLECTION_DISCOUNTS, $queries);
        });
    }

    // -------------------------------------------------------------------------
    // Wallets
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function createWallet(Document $wallet): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->createDocument(self::COLLECTION_WALLETS, $wallet)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getWallet(string $id): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->getDocument(self::COLLECTION_WALLETS, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getWalletByEntity(string $entityId): ?Document
    {
        $authorization = $this->db->getAuthorization();

        /** @var Document|null $doc */
        $doc = $authorization->skip(function () use ($entityId) {
            $results = $this->db->find(self::COLLECTION_WALLETS, [
                Query::equal('entityId', [$entityId]),
                Query::limit(1),
            ]);

            return $results[0] ?? null;
        });

        return $doc;
    }

    /**
     * {@inheritDoc}
     */
    public function updateWallet(string $id, Document $wallet): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->updateDocument(self::COLLECTION_WALLETS, $id, $wallet)
        );
    }

    // -------------------------------------------------------------------------
    // Transactions
    // -------------------------------------------------------------------------

    /**
     * {@inheritDoc}
     */
    public function createTransaction(Document $transaction): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->createDocument(self::COLLECTION_TRANSACTIONS, $transaction)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getTransaction(string $id): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->getDocument(self::COLLECTION_TRANSACTIONS, $id)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function updateTransaction(string $id, Document $transaction): Document
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(
            fn () => $this->db->updateDocument(self::COLLECTION_TRANSACTIONS, $id, $transaction)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listTransactions(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(function () use ($entityId, $filters, $limit, $offset) {
            $queries = [
                Query::equal('entityId', [$entityId]),
                Query::limit($limit),
                Query::offset($offset),
            ];

            if (isset($filters['type'])) {
                $queries[] = Query::equal('type', \is_array($filters['type']) ? $filters['type'] : [$filters['type']]);
            }

            if (isset($filters['status'])) {
                $queries[] = Query::equal('status', \is_array($filters['status']) ? $filters['status'] : [$filters['status']]);
            }

            if (isset($filters['walletId'])) {
                $queries[] = Query::equal('walletId', \is_array($filters['walletId']) ? $filters['walletId'] : [$filters['walletId']]);
            }

            return $this->db->find(self::COLLECTION_TRANSACTIONS, $queries);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function listInvoiceTransactions(string $invoiceId, int $limit = 25, int $offset = 0): array
    {
        $authorization = $this->db->getAuthorization();

        return $authorization->skip(function () use ($invoiceId, $limit, $offset) {
            return $this->db->find(self::COLLECTION_TRANSACTIONS, [
                Query::equal('invoiceId', [$invoiceId]),
                Query::limit($limit),
                Query::offset($offset),
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // Collection Setup (private helpers)
    // -------------------------------------------------------------------------

    /**
     * Check if a collection already exists.
     *
     * @param  UtopiaDatabase  $db  The database instance
     * @param  string  $name  The collection name
     * @return bool True if the collection already exists
     */
    private function collectionExists(UtopiaDatabase $db, string $name): bool
    {
        try {
            return ! $db->getCollection($name)->isEmpty();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create the subscriptions collection with all attributes and indexes.
     *
     * @param  UtopiaDatabase  $db  The database instance
     */
    private function createSubscriptionsCollection(UtopiaDatabase $db): void
    {
        if ($this->collectionExists($db, self::COLLECTION_SUBSCRIPTIONS)) {
            return;
        }

        $db->createCollection(self::COLLECTION_SUBSCRIPTIONS, permissions: [], documentSecurity: false);

        // Attributes
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'entityId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'entityType', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'planId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'status', UtopiaDatabase::VAR_STRING, 50, true);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'currentPeriodStart', UtopiaDatabase::VAR_DATETIME, 0, true);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'currentPeriodEnd', UtopiaDatabase::VAR_DATETIME, 0, true);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'trialStart', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'trialEnd', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'pendingPlanId', UtopiaDatabase::VAR_STRING, 255, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'pendingChangeType', UtopiaDatabase::VAR_STRING, 50, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'pendingChangedAt', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'pendingExpiresAt', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'pendingInvoiceId', UtopiaDatabase::VAR_STRING, 255, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'budget', UtopiaDatabase::VAR_FLOAT, 0, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'budgetUsed', UtopiaDatabase::VAR_FLOAT, 0, false, default: 0.0);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'budgetLimitReached', UtopiaDatabase::VAR_BOOLEAN, 0, false, default: false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'failedPaymentAttempts', UtopiaDatabase::VAR_INTEGER, 0, false, default: 0);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'nextRetryAt', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'lastFailedAt', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'cancelAtPeriodEnd', UtopiaDatabase::VAR_BOOLEAN, 0, false, default: false);
        $db->createAttribute(self::COLLECTION_SUBSCRIPTIONS, 'metadata', UtopiaDatabase::VAR_STRING, 65535, false);

        // Indexes
        $db->createIndex(self::COLLECTION_SUBSCRIPTIONS, 'idx_entityId', UtopiaDatabase::INDEX_KEY, ['entityId']);
        $db->createIndex(self::COLLECTION_SUBSCRIPTIONS, 'idx_status', UtopiaDatabase::INDEX_KEY, ['status']);
        $db->createIndex(self::COLLECTION_SUBSCRIPTIONS, 'idx_entityId_status', UtopiaDatabase::INDEX_KEY, ['entityId', 'status']);
    }

    /**
     * Create the invoices collection with all attributes and indexes.
     *
     * @param  UtopiaDatabase  $db  The database instance
     */
    private function createInvoicesCollection(UtopiaDatabase $db): void
    {
        if ($this->collectionExists($db, self::COLLECTION_INVOICES)) {
            return;
        }

        $db->createCollection(self::COLLECTION_INVOICES, permissions: [], documentSecurity: false);

        // Attributes
        $db->createAttribute(self::COLLECTION_INVOICES, 'subscriptionId', UtopiaDatabase::VAR_STRING, 255, false);
        $db->createAttribute(self::COLLECTION_INVOICES, 'referenceInvoiceId', UtopiaDatabase::VAR_STRING, 255, false);
        $db->createAttribute(self::COLLECTION_INVOICES, 'entityId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_INVOICES, 'type', UtopiaDatabase::VAR_STRING, 100, true);
        $db->createAttribute(self::COLLECTION_INVOICES, 'number', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_INVOICES, 'status', UtopiaDatabase::VAR_STRING, 50, true);
        $db->createAttribute(self::COLLECTION_INVOICES, 'items', UtopiaDatabase::VAR_STRING, 1000000, false);
        $db->createAttribute(self::COLLECTION_INVOICES, 'subtotal', UtopiaDatabase::VAR_FLOAT, 0, false, default: 0.0);
        $db->createAttribute(self::COLLECTION_INVOICES, 'discountTotal', UtopiaDatabase::VAR_FLOAT, 0, false, default: 0.0);
        $db->createAttribute(self::COLLECTION_INVOICES, 'taxTotal', UtopiaDatabase::VAR_FLOAT, 0, false, default: 0.0);
        $db->createAttribute(self::COLLECTION_INVOICES, 'total', UtopiaDatabase::VAR_FLOAT, 0, false, default: 0.0);
        $db->createAttribute(self::COLLECTION_INVOICES, 'walletDeducted', UtopiaDatabase::VAR_FLOAT, 0, false, default: 0.0);
        $db->createAttribute(self::COLLECTION_INVOICES, 'gatewayCharged', UtopiaDatabase::VAR_FLOAT, 0, false, default: 0.0);
        $db->createAttribute(self::COLLECTION_INVOICES, 'currency', UtopiaDatabase::VAR_STRING, 10, true);
        $db->createAttribute(self::COLLECTION_INVOICES, 'dueDate', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_INVOICES, 'paidAt', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_INVOICES, 'metadata', UtopiaDatabase::VAR_STRING, 65535, false);

        // Indexes
        $db->createIndex(self::COLLECTION_INVOICES, 'idx_entityId', UtopiaDatabase::INDEX_KEY, ['entityId']);
        $db->createIndex(self::COLLECTION_INVOICES, 'idx_subscriptionId', UtopiaDatabase::INDEX_KEY, ['subscriptionId']);
        $db->createIndex(self::COLLECTION_INVOICES, 'idx_status', UtopiaDatabase::INDEX_KEY, ['status']);
        $db->createIndex(self::COLLECTION_INVOICES, 'idx_entityId_status', UtopiaDatabase::INDEX_KEY, ['entityId', 'status']);
        $db->createIndex(self::COLLECTION_INVOICES, 'idx_entityId_type', UtopiaDatabase::INDEX_KEY, ['entityId', 'type']);
    }

    /**
     * Create the coupons collection with all attributes and indexes.
     *
     * @param  UtopiaDatabase  $db  The database instance
     */
    private function createCouponsCollection(UtopiaDatabase $db): void
    {
        if ($this->collectionExists($db, self::COLLECTION_COUPONS)) {
            return;
        }

        $db->createCollection(self::COLLECTION_COUPONS, permissions: [], documentSecurity: false);

        // Attributes
        $db->createAttribute(self::COLLECTION_COUPONS, 'code', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_COUPONS, 'type', UtopiaDatabase::VAR_STRING, 50, true);
        $db->createAttribute(self::COLLECTION_COUPONS, 'value', UtopiaDatabase::VAR_FLOAT, 0, true);
        $db->createAttribute(self::COLLECTION_COUPONS, 'currency', UtopiaDatabase::VAR_STRING, 10, false);
        $db->createAttribute(self::COLLECTION_COUPONS, 'duration', UtopiaDatabase::VAR_STRING, 50, true);
        $db->createAttribute(self::COLLECTION_COUPONS, 'durationInCycles', UtopiaDatabase::VAR_INTEGER, 0, false);
        $db->createAttribute(self::COLLECTION_COUPONS, 'maxRedemptions', UtopiaDatabase::VAR_INTEGER, 0, false);
        $db->createAttribute(self::COLLECTION_COUPONS, 'timesRedeemed', UtopiaDatabase::VAR_INTEGER, 0, false, default: 0);
        $db->createAttribute(self::COLLECTION_COUPONS, 'expiresAt', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_COUPONS, 'scope', UtopiaDatabase::VAR_STRING, 65535, false);
        $db->createAttribute(self::COLLECTION_COUPONS, 'active', UtopiaDatabase::VAR_BOOLEAN, 0, false, default: true);
        $db->createAttribute(self::COLLECTION_COUPONS, 'metadata', UtopiaDatabase::VAR_STRING, 65535, false);

        // Indexes
        $db->createIndex(self::COLLECTION_COUPONS, 'idx_code', UtopiaDatabase::INDEX_UNIQUE, ['code']);
        $db->createIndex(self::COLLECTION_COUPONS, 'idx_active', UtopiaDatabase::INDEX_KEY, ['active']);
    }

    /**
     * Create the discounts collection with all attributes and indexes.
     *
     * @param  UtopiaDatabase  $db  The database instance
     */
    private function createDiscountsCollection(UtopiaDatabase $db): void
    {
        if ($this->collectionExists($db, self::COLLECTION_DISCOUNTS)) {
            return;
        }

        $db->createCollection(self::COLLECTION_DISCOUNTS, permissions: [], documentSecurity: false);

        // Attributes
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'couponId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'subscriptionId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'entityId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'type', UtopiaDatabase::VAR_STRING, 50, true);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'value', UtopiaDatabase::VAR_FLOAT, 0, true);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'duration', UtopiaDatabase::VAR_STRING, 50, true);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'scope', UtopiaDatabase::VAR_STRING, 65535, false);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'cyclesTotal', UtopiaDatabase::VAR_INTEGER, 0, false);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'cyclesRemaining', UtopiaDatabase::VAR_INTEGER, 0, false);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'status', UtopiaDatabase::VAR_STRING, 50, true);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'appliedAt', UtopiaDatabase::VAR_DATETIME, 0, true);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'exhaustedAt', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'cancelledAt', UtopiaDatabase::VAR_DATETIME, 0, false);
        $db->createAttribute(self::COLLECTION_DISCOUNTS, 'metadata', UtopiaDatabase::VAR_STRING, 65535, false);

        // Indexes
        $db->createIndex(self::COLLECTION_DISCOUNTS, 'idx_subscriptionId', UtopiaDatabase::INDEX_KEY, ['subscriptionId']);
        $db->createIndex(self::COLLECTION_DISCOUNTS, 'idx_entityId', UtopiaDatabase::INDEX_KEY, ['entityId']);
        $db->createIndex(self::COLLECTION_DISCOUNTS, 'idx_couponId', UtopiaDatabase::INDEX_KEY, ['couponId']);
        $db->createIndex(self::COLLECTION_DISCOUNTS, 'idx_subscriptionId_status', UtopiaDatabase::INDEX_KEY, ['subscriptionId', 'status']);
    }

    /**
     * Create the wallets collection with all attributes and indexes.
     *
     * @param  UtopiaDatabase  $db  The database instance
     */
    private function createWalletsCollection(UtopiaDatabase $db): void
    {
        if ($this->collectionExists($db, self::COLLECTION_WALLETS)) {
            return;
        }

        $db->createCollection(self::COLLECTION_WALLETS, permissions: [], documentSecurity: false);

        // Attributes
        $db->createAttribute(self::COLLECTION_WALLETS, 'entityId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_WALLETS, 'balance', UtopiaDatabase::VAR_FLOAT, 0, false, default: 0.0);
        $db->createAttribute(self::COLLECTION_WALLETS, 'currency', UtopiaDatabase::VAR_STRING, 10, true);
        $db->createAttribute(self::COLLECTION_WALLETS, 'metadata', UtopiaDatabase::VAR_STRING, 65535, false);

        // Indexes
        $db->createIndex(self::COLLECTION_WALLETS, 'idx_entityId', UtopiaDatabase::INDEX_UNIQUE, ['entityId']);
    }

    /**
     * Create the transactions collection with all attributes and indexes.
     *
     * @param  UtopiaDatabase  $db  The database instance
     */
    private function createTransactionsCollection(UtopiaDatabase $db): void
    {
        if ($this->collectionExists($db, self::COLLECTION_TRANSACTIONS)) {
            return;
        }

        $db->createCollection(self::COLLECTION_TRANSACTIONS, permissions: [], documentSecurity: false);

        // Attributes
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'entityId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'invoiceId', UtopiaDatabase::VAR_STRING, 255, true);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'type', UtopiaDatabase::VAR_STRING, 100, true);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'amount', UtopiaDatabase::VAR_FLOAT, 0, true);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'status', UtopiaDatabase::VAR_STRING, 50, true);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'walletId', UtopiaDatabase::VAR_STRING, 255, false);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'providerPaymentId', UtopiaDatabase::VAR_STRING, 255, false);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'authCode', UtopiaDatabase::VAR_STRING, 255, false);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'description', UtopiaDatabase::VAR_STRING, 2000, true);
        $db->createAttribute(self::COLLECTION_TRANSACTIONS, 'metadata', UtopiaDatabase::VAR_STRING, 65535, false);

        // Indexes
        $db->createIndex(self::COLLECTION_TRANSACTIONS, 'idx_entityId', UtopiaDatabase::INDEX_KEY, ['entityId']);
        $db->createIndex(self::COLLECTION_TRANSACTIONS, 'idx_invoiceId', UtopiaDatabase::INDEX_KEY, ['invoiceId']);
        $db->createIndex(self::COLLECTION_TRANSACTIONS, 'idx_type', UtopiaDatabase::INDEX_KEY, ['type']);
        $db->createIndex(self::COLLECTION_TRANSACTIONS, 'idx_walletId', UtopiaDatabase::INDEX_KEY, ['walletId']);
    }
}
