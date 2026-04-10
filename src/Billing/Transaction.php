<?php

declare(strict_types=1);

namespace Utopia\Billing;

use Utopia\Database\Document;

/**
 * Transaction
 *
 * Document wrapper for the transactions collection. Provides a unified
 * ledger for ALL money movements in the billing system. Every transaction
 * has an invoiceId — even wallet top-ups generate a receipt invoice.
 */
class Transaction
{
    /**
     * Transaction constructor.
     *
     * @param  Document  $document  The underlying database document
     */
    public function __construct(protected Document $document) {}

    /**
     * Get the underlying database document.
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get the collection name for transactions.
     */
    public static function getName(): string
    {
        return 'transactions';
    }

    /**
     * Get the transaction ID.
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the entity ID that owns this transaction.
     */
    public function getEntityId(): string
    {
        return (string) $this->document->getAttribute('entityId', '');
    }

    /**
     * Set the entity ID.
     */
    public function setEntityId(string $entityId): self
    {
        $this->document->setAttribute('entityId', $entityId);

        return $this;
    }

    /**
     * Get the invoice ID linked to this transaction.
     */
    public function getInvoiceId(): string
    {
        return (string) $this->document->getAttribute('invoiceId', '');
    }

    /**
     * Set the invoice ID.
     */
    public function setInvoiceId(string $invoiceId): self
    {
        $this->document->setAttribute('invoiceId', $invoiceId);

        return $this;
    }

    /**
     * Get the transaction type.
     */
    public function getType(): TransactionType
    {
        return TransactionType::from((string) $this->document->getAttribute('type', 'gateway_charge'));
    }

    /**
     * Set the transaction type.
     */
    public function setType(TransactionType $type): self
    {
        $this->document->setAttribute('type', $type->value);

        return $this;
    }

    /**
     * Get the transaction amount.
     */
    public function getAmount(): float
    {
        return (float) $this->document->getAttribute('amount', 0.0);
    }

    /**
     * Set the transaction amount.
     */
    public function setAmount(float $amount): self
    {
        $this->document->setAttribute('amount', $amount);

        return $this;
    }

    /**
     * Get the transaction status.
     */
    public function getStatus(): TransactionStatus
    {
        return TransactionStatus::from((string) $this->document->getAttribute('status', 'pending'));
    }

    /**
     * Set the transaction status.
     */
    public function setStatus(TransactionStatus $status): self
    {
        $this->document->setAttribute('status', $status->value);

        return $this;
    }

    /**
     * Get the wallet ID (for wallet operations).
     *
     * @return string|null Null for non-wallet transactions
     */
    public function getWalletId(): ?string
    {
        return $this->document->getAttribute('walletId');
    }

    /**
     * Set the wallet ID.
     */
    public function setWalletId(?string $walletId): self
    {
        $this->document->setAttribute('walletId', $walletId);

        return $this;
    }

    /**
     * Get the provider payment ID (e.g., Stripe payment intent ID).
     *
     * @return string|null Null for non-gateway transactions
     */
    public function getProviderPaymentId(): ?string
    {
        return $this->document->getAttribute('providerPaymentId');
    }

    /**
     * Set the provider payment ID.
     */
    public function setProviderPaymentId(?string $providerPaymentId): self
    {
        $this->document->setAttribute('providerPaymentId', $providerPaymentId);

        return $this;
    }

    /**
     * Get the transaction description.
     */
    public function getDescription(): string
    {
        return (string) $this->document->getAttribute('description', '');
    }

    /**
     * Set the transaction description.
     *
     * @param  string  $description  Human-readable description
     */
    public function setDescription(string $description): self
    {
        $this->document->setAttribute('description', $description);

        return $this;
    }

    /**
     * Get the transaction metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $meta = $this->document->getAttribute('metadata', []);

        return \is_string($meta) ? (array) \json_decode($meta, true) : $meta;
    }

    /**
     * Set the transaction metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->document->setAttribute('metadata', \json_encode($metadata));

        return $this;
    }

    /**
     * Check if this is a wallet-related transaction.
     */
    public function isWalletTransaction(): bool
    {
        return $this->getType()->isWallet();
    }

    /**
     * Check if this is a gateway-related transaction.
     */
    public function isGatewayTransaction(): bool
    {
        return $this->getType()->isGateway();
    }

    /**
     * Check if this is a credit-related transaction.
     */
    public function isCreditTransaction(): bool
    {
        return $this->getType()->isCredit();
    }

    /**
     * Check if the transaction has succeeded.
     */
    public function isSucceeded(): bool
    {
        return $this->getStatus() === TransactionStatus::Succeeded;
    }

    /**
     * Check if the transaction has failed.
     */
    public function isFailed(): bool
    {
        return $this->getStatus() === TransactionStatus::Failed;
    }

    /**
     * Check if the transaction is pending.
     */
    public function isPending(): bool
    {
        return $this->getStatus() === TransactionStatus::Pending;
    }
}
