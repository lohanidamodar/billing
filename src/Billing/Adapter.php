<?php

declare(strict_types=1);

namespace Utopia\Billing;

use Utopia\Database\Document;

/**
 * Adapter
 *
 * Abstract adapter defining the persistence contract for the Billing library.
 * All data access operations required by the Billing facade are declared here.
 * Concrete implementations (e.g. Database adapter) provide the actual storage logic.
 */
abstract class Adapter
{
    /**
     * Set up the underlying storage (collections, tables, indexes, etc.).
     *
     * Implementations MUST be idempotent — calling setup() on an already-initialized
     * store should be a no-op.
     *
     *
     * @throws Exception
     */
    abstract public function setup(): void;

    // -------------------------------------------------------------------------
    // Subscriptions
    // -------------------------------------------------------------------------

    /**
     * Create a new subscription document.
     *
     * @param  Document  $subscription  The subscription document to persist
     * @return Document The created subscription with generated ID
     *
     * @throws Exception
     */
    abstract public function createSubscription(Document $subscription): Document;

    /**
     * Get a subscription by its unique ID.
     *
     * @param  string  $id  The subscription ID
     * @return Document The subscription document
     *
     * @throws Exception If subscription not found
     */
    abstract public function getSubscription(string $id): Document;

    /**
     * Get the currently active subscription for an entity.
     *
     * Returns the first subscription with a non-terminal status (active, trialing,
     * past_due, canceling, or incomplete) for the given entity.
     *
     * @param  string  $entityId  The entity ID (user, team, organization)
     * @return Document|null The active subscription, or null if none exists
     */
    abstract public function getActiveSubscription(string $entityId): ?Document;

    /**
     * Update an existing subscription document.
     *
     * @param  string  $id  The subscription ID
     * @param  Document  $subscription  The updated subscription document
     * @return Document The updated subscription
     *
     * @throws Exception If subscription not found
     */
    abstract public function updateSubscription(string $id, Document $subscription): Document;

    /**
     * Delete a subscription by its unique ID.
     *
     * @param  string  $id  The subscription ID
     * @return bool True if deleted successfully
     *
     * @throws Exception
     */
    abstract public function deleteSubscription(string $id): bool;

    /**
     * List subscriptions for an entity, optionally filtered.
     *
     * Supported filter keys: 'status', 'planId'.
     *
     * @param  string  $entityId  The entity ID
     * @param  array<string, mixed>  $filters  Optional key-value filters
     * @param  int  $limit  Maximum number of results (default 25)
     * @param  int  $offset  Number of results to skip (default 0)
     * @return array<Document> List of subscription documents
     */
    abstract public function listSubscriptions(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array;

    // -------------------------------------------------------------------------
    // Invoices
    // -------------------------------------------------------------------------

    /**
     * Create a new invoice document.
     *
     * @param  Document  $invoice  The invoice document to persist
     * @return Document The created invoice with generated ID
     *
     * @throws Exception
     */
    abstract public function createInvoice(Document $invoice): Document;

    /**
     * Get an invoice by its unique ID.
     *
     * @param  string  $id  The invoice ID
     * @return Document The invoice document
     *
     * @throws Exception If invoice not found
     */
    abstract public function getInvoice(string $id): Document;

    /**
     * Update an existing invoice document.
     *
     * @param  string  $id  The invoice ID
     * @param  Document  $invoice  The updated invoice document
     * @return Document The updated invoice
     *
     * @throws Exception If invoice not found
     */
    abstract public function updateInvoice(string $id, Document $invoice): Document;

    /**
     * Delete an invoice by its unique ID.
     *
     * @param  string  $id  The invoice ID
     * @return bool True if deleted successfully
     *
     * @throws Exception
     */
    abstract public function deleteInvoice(string $id): bool;

    /**
     * List invoices for an entity, optionally filtered.
     *
     * Supported filter keys: 'status', 'type', 'subscriptionId'.
     *
     * @param  string  $entityId  The entity ID
     * @param  array<string, mixed>  $filters  Optional key-value filters
     * @param  int  $limit  Maximum number of results (default 25)
     * @param  int  $offset  Number of results to skip (default 0)
     * @return array<Document> List of invoice documents
     */
    abstract public function listInvoices(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array;

    /**
     * Get the next invoice sequence number for number generation.
     *
     * Returns a monotonically increasing integer used to build invoice numbers
     * such as INV-2026-00042.
     *
     * @return int The next sequence number
     */
    abstract public function getNextInvoiceSequence(): int;

    // -------------------------------------------------------------------------
    // Coupons
    // -------------------------------------------------------------------------

    /**
     * Create a new coupon document.
     *
     * @param  Document  $coupon  The coupon document to persist
     * @return Document The created coupon with generated ID
     *
     * @throws Exception
     */
    abstract public function createCoupon(Document $coupon): Document;

    /**
     * Get a coupon by its unique ID.
     *
     * @param  string  $id  The coupon ID
     * @return Document The coupon document
     *
     * @throws Exception If coupon not found
     */
    abstract public function getCoupon(string $id): Document;

    /**
     * Get a coupon by its unique user-facing code.
     *
     * @param  string  $code  The coupon code (e.g. 'SUMMER20')
     * @return Document The coupon document
     *
     * @throws Exception If coupon not found
     */
    abstract public function getCouponByCode(string $code): Document;

    /**
     * Update an existing coupon document.
     *
     * @param  string  $id  The coupon ID
     * @param  Document  $coupon  The updated coupon document
     * @return Document The updated coupon
     *
     * @throws Exception If coupon not found
     */
    abstract public function updateCoupon(string $id, Document $coupon): Document;

    /**
     * Delete a coupon by its unique ID.
     *
     * @param  string  $id  The coupon ID
     * @return bool True if deleted successfully
     *
     * @throws Exception
     */
    abstract public function deleteCoupon(string $id): bool;

    /**
     * List coupons, optionally filtered.
     *
     * Supported filter keys: 'active', 'type', 'duration'.
     *
     * @param  array<string, mixed>  $filters  Optional key-value filters
     * @param  int  $limit  Maximum number of results (default 25)
     * @param  int  $offset  Number of results to skip (default 0)
     * @return array<Document> List of coupon documents
     */
    abstract public function listCoupons(array $filters = [], int $limit = 25, int $offset = 0): array;

    // -------------------------------------------------------------------------
    // Discounts
    // -------------------------------------------------------------------------

    /**
     * Create a new discount document.
     *
     * @param  Document  $discount  The discount document to persist
     * @return Document The created discount with generated ID
     *
     * @throws Exception
     */
    abstract public function createDiscount(Document $discount): Document;

    /**
     * Get a discount by its unique ID.
     *
     * @param  string  $id  The discount ID
     * @return Document The discount document
     *
     * @throws Exception If discount not found
     */
    abstract public function getDiscount(string $id): Document;

    /**
     * Update an existing discount document.
     *
     * @param  string  $id  The discount ID
     * @param  Document  $discount  The updated discount document
     * @return Document The updated discount
     *
     * @throws Exception If discount not found
     */
    abstract public function updateDiscount(string $id, Document $discount): Document;

    /**
     * Delete a discount by its unique ID.
     *
     * @param  string  $id  The discount ID
     * @return bool True if deleted successfully
     *
     * @throws Exception
     */
    abstract public function deleteDiscount(string $id): bool;

    /**
     * List discounts for a subscription, optionally filtered.
     *
     * Supported filter keys: 'status', 'couponId', 'entityId'.
     *
     * @param  string  $subscriptionId  The subscription ID
     * @param  array<string, mixed>  $filters  Optional key-value filters
     * @param  int  $limit  Maximum number of results (default 25)
     * @param  int  $offset  Number of results to skip (default 0)
     * @return array<Document> List of discount documents
     */
    abstract public function listDiscounts(string $subscriptionId, array $filters = [], int $limit = 25, int $offset = 0): array;

    // -------------------------------------------------------------------------
    // Wallets
    // -------------------------------------------------------------------------

    /**
     * Create a new wallet document.
     *
     * @param  Document  $wallet  The wallet document to persist
     * @return Document The created wallet with generated ID
     *
     * @throws Exception
     */
    abstract public function createWallet(Document $wallet): Document;

    /**
     * Get a wallet by its unique ID.
     *
     * @param  string  $id  The wallet ID
     * @return Document The wallet document
     *
     * @throws Exception If wallet not found
     */
    abstract public function getWallet(string $id): Document;

    /**
     * Get a wallet by its owning entity ID.
     *
     * @param  string  $entityId  The entity ID (user, team, organization)
     * @return Document|null The wallet document, or null if none exists
     */
    abstract public function getWalletByEntity(string $entityId): ?Document;

    /**
     * Update an existing wallet document.
     *
     * @param  string  $id  The wallet ID
     * @param  Document  $wallet  The updated wallet document
     * @return Document The updated wallet
     *
     * @throws Exception If wallet not found
     */
    abstract public function updateWallet(string $id, Document $wallet): Document;

    // -------------------------------------------------------------------------
    // Transactions
    // -------------------------------------------------------------------------

    /**
     * Create a new transaction document.
     *
     * @param  Document  $transaction  The transaction document to persist
     * @return Document The created transaction with generated ID
     *
     * @throws Exception
     */
    abstract public function createTransaction(Document $transaction): Document;

    /**
     * Get a transaction by its unique ID.
     *
     * @param  string  $id  The transaction ID
     * @return Document The transaction document
     *
     * @throws Exception If transaction not found
     */
    abstract public function getTransaction(string $id): Document;

    /**
     * List transactions for an entity, optionally filtered.
     *
     * Supported filter keys: 'type', 'status', 'walletId'.
     *
     * @param  string  $entityId  The entity ID
     * @param  array<string, mixed>  $filters  Optional key-value filters
     * @param  int  $limit  Maximum number of results (default 25)
     * @param  int  $offset  Number of results to skip (default 0)
     * @return array<Document> List of transaction documents
     */
    abstract public function listTransactions(string $entityId, array $filters = [], int $limit = 25, int $offset = 0): array;

    /**
     * Update an existing transaction document.
     *
     * @param  string  $id  The transaction ID
     * @param  Document  $transaction  The updated transaction document
     * @return Document The updated transaction
     *
     * @throws Exception If transaction not found
     */
    abstract public function updateTransaction(string $id, Document $transaction): Document;

    /**
     * List all transactions associated with a specific invoice.
     *
     * @param  string  $invoiceId  The invoice ID
     * @param  int  $limit  Maximum number of results (default 25)
     * @param  int  $offset  Number of results to skip (default 0)
     * @return array<Document> List of transaction documents
     */
    abstract public function listInvoiceTransactions(string $invoiceId, int $limit = 25, int $offset = 0): array;
}
