<?php

declare(strict_types=1);

namespace Utopia\Billing;

use Utopia\Database\Document;

/**
 * Invoice
 *
 * Document wrapper for the invoices collection. Represents a billing invoice
 * with denormalized line items, computed totals, and payment tracking.
 *
 * Invoices are created at cycle end only and are immutable once finalized.
 */
class Invoice
{
    /**
     * Invoice status constants.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_VOIDED = 'voided';

    /**
     * Invoice type constants.
     *
     * These are the common types; the library is type-agnostic and
     * accepts any string value the consuming app defines.
     */
    public const TYPE_SUBSCRIPTION = 'subscription';
    public const TYPE_DOMAIN_PURCHASE = 'domain_purchase';
    public const TYPE_DOMAIN_RENEWAL = 'domain_renewal';
    public const TYPE_WALLET_TOPUP = 'wallet_topup';
    public const TYPE_CREDIT_NOTE = 'credit_note';

    /**
     * Line item type constants.
     */
    public const ITEM_TYPE_PLAN = 'plan';
    public const ITEM_TYPE_USAGE = 'usage';
    public const ITEM_TYPE_ADDON = 'addon';
    public const ITEM_TYPE_DISCOUNT = 'discount';
    public const ITEM_TYPE_TAX = 'tax';
    public const ITEM_TYPE_PRORATION = 'proration';
    public const ITEM_TYPE_REFUND = 'refund';

    /**
     * Invoice constructor.
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
     * Get the collection name for invoices.
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'invoices';
    }

    /**
     * Get the invoice ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the subscription ID (null for one-off invoices).
     *
     * @return string|null
     */
    public function getSubscriptionId(): ?string
    {
        return $this->document->getAttribute('subscriptionId');
    }

    /**
     * Set the subscription ID.
     *
     * @param string|null $subscriptionId
     *
     * @return self
     */
    public function setSubscriptionId(?string $subscriptionId): self
    {
        $this->document->setAttribute('subscriptionId', $subscriptionId);

        return $this;
    }

    /**
     * Get the reference invoice ID (set for credit notes).
     *
     * @return string|null
     */
    public function getReferenceInvoiceId(): ?string
    {
        return $this->document->getAttribute('referenceInvoiceId');
    }

    /**
     * Set the reference invoice ID.
     *
     * @param string|null $referenceInvoiceId
     *
     * @return self
     */
    public function setReferenceInvoiceId(?string $referenceInvoiceId): self
    {
        $this->document->setAttribute('referenceInvoiceId', $referenceInvoiceId);

        return $this;
    }

    /**
     * Get the entity ID.
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
     * Get the invoice type.
     *
     * @return string One of TYPE_* constants or an app-defined type
     */
    public function getType(): string
    {
        return $this->document->getAttribute('type', '');
    }

    /**
     * Set the invoice type.
     *
     * @param string $type One of TYPE_* constants or an app-defined type
     *
     * @return self
     */
    public function setType(string $type): self
    {
        $this->document->setAttribute('type', $type);

        return $this;
    }

    /**
     * Get the invoice number (e.g., INV-2026-00001).
     *
     * @return string
     */
    public function getNumber(): string
    {
        return $this->document->getAttribute('number', '');
    }

    /**
     * Set the invoice number.
     *
     * @param string $number
     *
     * @return self
     */
    public function setNumber(string $number): self
    {
        $this->document->setAttribute('number', $number);

        return $this;
    }

    /**
     * Get the invoice status.
     *
     * @return string One of STATUS_* constants
     */
    public function getStatus(): string
    {
        return $this->document->getAttribute('status', '');
    }

    /**
     * Set the invoice status.
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
     * Get the invoice line items.
     *
     * Each item is an associative array with keys: type, description, resource,
     * quantity, unit, unitPrice, amount, discountId, rate, metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->document->getAttribute('items', []);
    }

    /**
     * Set the invoice line items.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return self
     */
    public function setItems(array $items): self
    {
        $this->document->setAttribute('items', $items);

        return $this;
    }

    /**
     * Add a single line item to the invoice.
     *
     * @param array<string, mixed> $item Line item array
     *
     * @return self
     */
    public function addItem(array $item): self
    {
        $items = $this->getItems();
        $items[] = $item;
        $this->document->setAttribute('items', $items);

        return $this;
    }

    /**
     * Get the subtotal (sum of plan + usage + addon items, before discounts and tax).
     *
     * @return float
     */
    public function getSubtotal(): float
    {
        return $this->document->getAttribute('subtotal', 0.0);
    }

    /**
     * Set the subtotal.
     *
     * @param float $subtotal
     *
     * @return self
     */
    public function setSubtotal(float $subtotal): self
    {
        $this->document->setAttribute('subtotal', $subtotal);

        return $this;
    }

    /**
     * Get the discount total (sum of discount line items, negative value).
     *
     * @return float
     */
    public function getDiscountTotal(): float
    {
        return $this->document->getAttribute('discountTotal', 0.0);
    }

    /**
     * Set the discount total.
     *
     * @param float $discountTotal
     *
     * @return self
     */
    public function setDiscountTotal(float $discountTotal): self
    {
        $this->document->setAttribute('discountTotal', $discountTotal);

        return $this;
    }

    /**
     * Get the tax total (sum of tax line items).
     *
     * @return float
     */
    public function getTaxTotal(): float
    {
        return $this->document->getAttribute('taxTotal', 0.0);
    }

    /**
     * Set the tax total.
     *
     * @param float $taxTotal
     *
     * @return self
     */
    public function setTaxTotal(float $taxTotal): self
    {
        $this->document->setAttribute('taxTotal', $taxTotal);

        return $this;
    }

    /**
     * Get the invoice total (subtotal + discountTotal + taxTotal).
     *
     * @return float
     */
    public function getTotal(): float
    {
        return $this->document->getAttribute('total', 0.0);
    }

    /**
     * Set the invoice total.
     *
     * @param float $total
     *
     * @return self
     */
    public function setTotal(float $total): self
    {
        $this->document->setAttribute('total', $total);

        return $this;
    }

    /**
     * Get the amount deducted from the wallet during payment.
     *
     * @return float
     */
    public function getWalletDeducted(): float
    {
        return $this->document->getAttribute('walletDeducted', 0.0);
    }

    /**
     * Set the amount deducted from the wallet during payment.
     *
     * @param float $walletDeducted
     *
     * @return self
     */
    public function setWalletDeducted(float $walletDeducted): self
    {
        $this->document->setAttribute('walletDeducted', $walletDeducted);

        return $this;
    }

    /**
     * Get the amount charged to the payment gateway.
     *
     * @return float
     */
    public function getGatewayCharged(): float
    {
        return $this->document->getAttribute('gatewayCharged', 0.0);
    }

    /**
     * Set the amount charged to the payment gateway.
     *
     * @param float $gatewayCharged
     *
     * @return self
     */
    public function setGatewayCharged(float $gatewayCharged): self
    {
        $this->document->setAttribute('gatewayCharged', $gatewayCharged);

        return $this;
    }

    /**
     * Get the invoice currency code.
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->document->getAttribute('currency', '');
    }

    /**
     * Set the invoice currency code.
     *
     * @param string $currency ISO 4217 currency code (e.g., 'USD')
     *
     * @return self
     */
    public function setCurrency(string $currency): self
    {
        $this->document->setAttribute('currency', $currency);

        return $this;
    }

    /**
     * Get the invoice due date.
     *
     * @return string|null
     */
    public function getDueDate(): ?string
    {
        return $this->document->getAttribute('dueDate');
    }

    /**
     * Set the invoice due date.
     *
     * @param string|null $dueDate ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setDueDate(?string $dueDate): self
    {
        $this->document->setAttribute('dueDate', $dueDate);

        return $this;
    }

    /**
     * Get the datetime when the invoice was paid.
     *
     * @return string|null
     */
    public function getPaidAt(): ?string
    {
        return $this->document->getAttribute('paidAt');
    }

    /**
     * Set the datetime when the invoice was paid.
     *
     * @param string|null $paidAt ISO 8601 datetime string or null
     *
     * @return self
     */
    public function setPaidAt(?string $paidAt): self
    {
        $this->document->setAttribute('paidAt', $paidAt);

        return $this;
    }

    /**
     * Get the invoice metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->document->getAttribute('metadata', []);
    }

    /**
     * Set the invoice metadata.
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
     * Check if the invoice is in a finalized or later state (immutable).
     *
     * @return bool
     */
    public function isFinalized(): bool
    {
        return match ($this->getStatus()) {
            self::STATUS_FINALIZED,
            self::STATUS_PAID,
            self::STATUS_FAILED,
            self::STATUS_VOIDED => true,
            default => false,
        };
    }

    /**
     * Check if this invoice is a credit note.
     *
     * @return bool
     */
    public function isCreditNote(): bool
    {
        return $this->getType() === self::TYPE_CREDIT_NOTE;
    }

    /**
     * Get items filtered by type.
     *
     * @param string $type One of ITEM_TYPE_* constants
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItemsByType(string $type): array
    {
        return \array_values(\array_filter(
            $this->getItems(),
            fn (array $item): bool => ($item['type'] ?? '') === $type
        ));
    }
}
