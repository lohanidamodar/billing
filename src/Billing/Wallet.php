<?php

declare(strict_types=1);

namespace Utopia\Billing;

use Utopia\Database\Document;

/**
 * Wallet
 *
 * Document wrapper for the wallets collection. A wallet is a user-funded
 * prepaid balance. Fixed coupon credits also go here. Each entity has
 * at most one wallet per currency.
 */
class Wallet
{
    /**
     * Wallet constructor.
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
     * Get the collection name for wallets.
     */
    public static function getName(): string
    {
        return 'wallets';
    }

    /**
     * Get the wallet ID.
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the entity ID that owns this wallet.
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
     * Get the current wallet balance.
     */
    public function getBalance(): float
    {
        return (float) $this->document->getAttribute('balance', 0.0);
    }

    /**
     * Set the wallet balance.
     */
    public function setBalance(float $balance): self
    {
        $this->document->setAttribute('balance', $balance);

        return $this;
    }

    /**
     * Get the wallet currency code.
     *
     * @return string ISO 4217 currency code (e.g., 'USD')
     */
    public function getCurrency(): string
    {
        return $this->document->getAttribute('currency', 'USD');
    }

    /**
     * Set the wallet currency code.
     *
     * @param  string  $currency  ISO 4217 currency code (e.g., 'USD')
     */
    public function setCurrency(string $currency): self
    {
        $this->document->setAttribute('currency', $currency);

        return $this;
    }

    /**
     * Get the wallet metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $meta = $this->document->getAttribute('metadata', []);

        return \is_string($meta) ? (array) \json_decode($meta, true) : $meta;
    }

    /**
     * Set the wallet metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->document->setAttribute('metadata', \json_encode($metadata));

        return $this;
    }

    /**
     * Check if the wallet has sufficient balance for a given amount.
     *
     * @param  float  $amount  The amount to check against the balance
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->getBalance() >= $amount;
    }

    /**
     * Check if the wallet has a zero balance.
     */
    public function isEmpty(): bool
    {
        return $this->getBalance() <= 0.0;
    }
}
