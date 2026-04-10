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
 *
 * Invoice types are app-defined strings — the library is type-agnostic.
 * Common types: 'subscription', 'wallet_topup', 'credit_note'.
 * The app may define any custom types (e.g., 'domain_purchase', 'addon_hipaa').
 */
class Invoice
{
    /**
     * Line item type constants.
     *
     * These are the built-in line item types used by the finalization engine.
     * Apps may extend with custom types.
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
     * @param  Document  $document  The underlying database document
     */
    public function __construct(protected Document $document)
    {
    }

    /**
     * Get the underlying database document.
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Get the collection name for invoices.
     */
    public static function getName(): string
    {
        return 'invoices';
    }

    /**
     * Get the invoice ID.
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the subscription ID (null for one-off invoices).
     */
    public function getSubscriptionId(): ?string
    {
        return $this->document->getAttribute('subscriptionId');
    }

    /**
     * Set the subscription ID.
     */
    public function setSubscriptionId(?string $subscriptionId): self
    {
        $this->document->setAttribute('subscriptionId', $subscriptionId);

        return $this;
    }

    /**
     * Get the reference invoice ID (set for credit notes).
     */
    public function getReferenceInvoiceId(): ?string
    {
        return $this->document->getAttribute('referenceInvoiceId');
    }

    /**
     * Set the reference invoice ID.
     */
    public function setReferenceInvoiceId(?string $referenceInvoiceId): self
    {
        $this->document->setAttribute('referenceInvoiceId', $referenceInvoiceId);

        return $this;
    }

    /**
     * Get the entity ID.
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
     * Get the invoice type (app-defined string).
     */
    public function getType(): string
    {
        return (string) $this->document->getAttribute('type', '');
    }

    /**
     * Set the invoice type (app-defined string).
     *
     * @param  string  $type  Any app-defined type string
     */
    public function setType(string $type): self
    {
        $this->document->setAttribute('type', $type);

        return $this;
    }

    /**
     * Get the invoice number (e.g., INV-2026-00001).
     */
    public function getNumber(): string
    {
        return (string) $this->document->getAttribute('number', '');
    }

    /**
     * Set the invoice number.
     */
    public function setNumber(string $number): self
    {
        $this->document->setAttribute('number', $number);

        return $this;
    }

    /**
     * Get the invoice status.
     */
    public function getStatus(): InvoiceStatus
    {
        return InvoiceStatus::from((string) $this->document->getAttribute('status', 'draft'));
    }

    /**
     * Set the invoice status.
     */
    public function setStatus(InvoiceStatus $status): self
    {
        $this->document->setAttribute('status', $status->value);

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
        $items = $this->document->getAttribute('items', []);

        if (\is_string($items)) {
            return (array) \json_decode($items, true);
        }

        return $items;
    }

    /**
     * Set the invoice line items.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function setItems(array $items): self
    {
        $this->document->setAttribute('items', \json_encode($items));

        return $this;
    }

    /**
     * Add a single line item to the invoice.
     *
     * @param  array<string, mixed>  $item  Line item array
     */
    public function addItem(array $item): self
    {
        $items = $this->getItems();
        $items[] = $item;
        $this->document->setAttribute('items', \json_encode($items));

        return $this;
    }

    /**
     * Get the subtotal (sum of plan + usage + addon items, before discounts and tax).
     */
    public function getSubtotal(): float
    {
        return (float) $this->document->getAttribute('subtotal', 0.0);
    }

    /**
     * Set the subtotal.
     */
    public function setSubtotal(float $subtotal): self
    {
        $this->document->setAttribute('subtotal', $subtotal);

        return $this;
    }

    /**
     * Get the discount total (sum of discount line items, negative value).
     */
    public function getDiscountTotal(): float
    {
        return (float) $this->document->getAttribute('discountTotal', 0.0);
    }

    /**
     * Set the discount total.
     */
    public function setDiscountTotal(float $discountTotal): self
    {
        $this->document->setAttribute('discountTotal', $discountTotal);

        return $this;
    }

    /**
     * Get the tax total (sum of tax line items).
     */
    public function getTaxTotal(): float
    {
        return (float) $this->document->getAttribute('taxTotal', 0.0);
    }

    /**
     * Set the tax total.
     */
    public function setTaxTotal(float $taxTotal): self
    {
        $this->document->setAttribute('taxTotal', $taxTotal);

        return $this;
    }

    /**
     * Get the invoice total (subtotal + discountTotal + taxTotal).
     */
    public function getTotal(): float
    {
        return (float) $this->document->getAttribute('total', 0.0);
    }

    /**
     * Set the invoice total.
     */
    public function setTotal(float $total): self
    {
        $this->document->setAttribute('total', $total);

        return $this;
    }

    /**
     * Get the amount deducted from the wallet during payment.
     */
    public function getWalletDeducted(): float
    {
        return (float) $this->document->getAttribute('walletDeducted', 0.0);
    }

    /**
     * Set the amount deducted from the wallet during payment.
     */
    public function setWalletDeducted(float $walletDeducted): self
    {
        $this->document->setAttribute('walletDeducted', $walletDeducted);

        return $this;
    }

    /**
     * Get the amount charged to the payment gateway.
     */
    public function getGatewayCharged(): float
    {
        return (float) $this->document->getAttribute('gatewayCharged', 0.0);
    }

    /**
     * Set the amount charged to the payment gateway.
     */
    public function setGatewayCharged(float $gatewayCharged): self
    {
        $this->document->setAttribute('gatewayCharged', $gatewayCharged);

        return $this;
    }

    /**
     * Get the invoice currency code.
     */
    public function getCurrency(): string
    {
        return (string) $this->document->getAttribute('currency', '');
    }

    /**
     * Set the invoice currency code.
     *
     * @param  string  $currency  ISO 4217 currency code (e.g., 'USD')
     */
    public function setCurrency(string $currency): self
    {
        $this->document->setAttribute('currency', $currency);

        return $this;
    }

    /**
     * Get the invoice due date.
     */
    public function getDueDate(): ?string
    {
        return $this->document->getAttribute('dueDate');
    }

    /**
     * Set the invoice due date.
     *
     * @param  string|null  $dueDate  ISO 8601 datetime string or null
     */
    public function setDueDate(?string $dueDate): self
    {
        $this->document->setAttribute('dueDate', $dueDate);

        return $this;
    }

    /**
     * Get the datetime when the invoice was paid.
     */
    public function getPaidAt(): ?string
    {
        return $this->document->getAttribute('paidAt');
    }

    /**
     * Set the datetime when the invoice was paid.
     *
     * @param  string|null  $paidAt  ISO 8601 datetime string or null
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
        $meta = $this->document->getAttribute('metadata', []);

        return \is_string($meta) ? (array) \json_decode($meta, true) : $meta;
    }

    /**
     * Set the invoice metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->document->setAttribute('metadata', \json_encode($metadata));

        return $this;
    }

    /**
     * Check if the invoice is in a finalized or later state (immutable).
     */
    public function isFinalized(): bool
    {
        return $this->getStatus()->isFinalized();
    }

    /**
     * Check if this invoice is a credit note.
     */
    public function isCreditNote(): bool
    {
        return $this->getType() === 'credit_note';
    }

    /**
     * Get items filtered by type.
     *
     * @param  string  $type  One of ITEM_TYPE_* constants or any custom type
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
