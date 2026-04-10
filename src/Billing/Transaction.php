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
     * Transaction type constants.
     */
    public const TYPE_GATEWAY_CHARGE = 'gateway_charge';
    public const TYPE_GATEWAY_REFUND = 'gateway_refund';
    public const TYPE_WALLET_TOPUP = 'wallet_topup';
    public const TYPE_WALLET_DEDUCTION = 'wallet_deduction';
    public const TYPE_WALLET_REFUND = 'wallet_refund';
    public const TYPE_COUPON_CREDIT = 'coupon_credit';
    public const TYPE_CREDIT_EXPIRY = 'credit_expiry';

    /**
     * Transaction status constants.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /**
     * Transaction constructor.
     *
     * @param Document $document The underlying database document
     */
    public function __construct(protected Document $document)
    {
    }

    /**
     * Get the underlying database document.
     *
     * @return Document
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get the collection name for transactions.
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'transactions';
    }

    /**
     * Get the transaction ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the entity ID that owns this transaction.
     *
     * @return string
     */
    public function getEntityId(): string
    {
        return $this->document->getAttribute('entityId', '');
    }

    /**
     * Set the entity ID.
     *
     * @param string $entityId
     *
     * @return self
     */
    public function setEntityId(string $entityId): self
    {
        $this->document->setAttribute('entityId', $entityId);

        return $this;
    }

    /**
     * Get the invoice ID linked to this transaction.
     *
     * Every transaction must have an invoiceId.
     *
     * @return string
     */
    public function getInvoiceId(): string
    {
        return $this->document->getAttribute('invoiceId', '');
    }

    /**
     * Set the invoice ID.
     *
     * @param string $invoiceId
     *
     * @return self
     */
    public function setInvoiceId(string $invoiceId): self
    {
        $this->document->setAttribute('invoiceId', $invoiceId);

        return $this;
    }

    /**
     * Get the transaction type.
     *
     * @return string One of TYPE_* constants
     */
    public function getType(): string
    {
        return $this->document->getAttribute('type', '');
    }

    /**
     * Set the transaction type.
     *
     * @param string $type One of TYPE_* constants
     *
     * @return self
     */
    public function setType(string $type): self
    {
        $this->document->setAttribute('type', $type);

        return $this;
    }

    /**
     * Get the transaction amount.
     *
     * @return float
     */
    public function getAmount(): float
    {
        return $this->document->getAttribute('amount', 0.0);
    }

    /**
     * Set the transaction amount.
     *
     * @param float $amount
     *
     * @return self
     */
    public function setAmount(float $amount): self
    {
        $this->document->setAttribute('amount', $amount);

        return $this;
    }

    /**
     * Get the transaction status.
     *
     * @return string One of STATUS_* constants
     */
    public function getStatus(): string
    {
        return $this->document->getAttribute('status', '');
    }

    /**
     * Set the transaction status.
     *
     * @param string $status One of STATUS_* constants
     *
     * @return self
     */
    public function setStatus(string $status): self
    {
        $this->document->setAttribute('status', $status);

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
     *
     * @param string|null $walletId
     *
     * @return self
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
     *
     * @param string|null $providerPaymentId
     *
     * @return self
     */
    public function setProviderPaymentId(?string $providerPaymentId): self
    {
        $this->document->setAttribute('providerPaymentId', $providerPaymentId);

        return $this;
    }

    /**
     * Get the transaction description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->document->getAttribute('description', '');
    }

    /**
     * Set the transaction description.
     *
     * @param string $description Human-readable description
     *
     * @return self
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
        return $this->document->getAttribute('metadata', []);
    }

    /**
     * Set the transaction metadata.
     *
     * @param array<string, mixed> $metadata
     *
     * @return self
     */
    public function setMetadata(array $metadata): self
    {
        $this->document->setAttribute('metadata', $metadata);

        return $this;
    }

    /**
     * Check if this is a wallet-related transaction.
     *
     * @return bool
     */
    public function isWalletTransaction(): bool
    {
        return match ($this->getType()) {
            self::TYPE_WALLET_TOPUP,
            self::TYPE_WALLET_DEDUCTION,
            self::TYPE_WALLET_REFUND => true,
            default => false,
        };
    }

    /**
     * Check if this is a gateway-related transaction.
     *
     * @return bool
     */
    public function isGatewayTransaction(): bool
    {
        return match ($this->getType()) {
            self::TYPE_GATEWAY_CHARGE,
            self::TYPE_GATEWAY_REFUND => true,
            default => false,
        };
    }

    /**
     * Check if this is a credit-related transaction.
     *
     * @return bool
     */
    public function isCreditTransaction(): bool
    {
        return match ($this->getType()) {
            self::TYPE_COUPON_CREDIT,
            self::TYPE_CREDIT_EXPIRY => true,
            default => false,
        };
    }

    /**
     * Check if the transaction has succeeded.
     *
     * @return bool
     */
    public function isSucceeded(): bool
    {
        return $this->getStatus() === self::STATUS_SUCCEEDED;
    }

    /**
     * Check if the transaction has failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->getStatus() === self::STATUS_FAILED;
    }

    /**
     * Check if the transaction is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->getStatus() === self::STATUS_PENDING;
    }
}
