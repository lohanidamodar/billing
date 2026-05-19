<?php

declare(strict_types=1);

namespace Utopia\Billing;

use Utopia\Database\Document;

/**
 * Discount
 *
 * Document wrapper for the discounts collection. A discount is an applied
 * instance of a coupon on a specific subscription. It tracks the cycle
 * countdown for repeating discounts (Lago pattern).
 *
 * Duration behavior:
 * - once: cyclesRemaining 1 -> 0 -> status: exhausted
 * - repeating(N): cyclesRemaining N -> N-1 -> ... -> 0 -> status: exhausted
 * - forever: cyclesRemaining null, applied every cycle until admin cancels
 *
 * Scope determines discount level:
 * - scope: null -> invoice-level (applied to subtotal)
 * - scope: { resources: ['bandwidth'] } -> line-level (only matching items)
 */
class Discount
{
    /**
     * Discount constructor.
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
     * Get the collection name for discounts.
     */
    public static function getName(): string
    {
        return 'discounts';
    }

    /**
     * Get the discount ID.
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the coupon ID this discount was created from.
     */
    public function getCouponId(): string
    {
        return (string) $this->document->getAttribute('couponId', '');
    }

    /**
     * Set the coupon ID.
     */
    public function setCouponId(string $couponId): self
    {
        $this->document->setAttribute('couponId', $couponId);

        return $this;
    }

    /**
     * Get the subscription ID this discount is applied to.
     */
    public function getSubscriptionId(): string
    {
        return (string) $this->document->getAttribute('subscriptionId', '');
    }

    /**
     * Set the subscription ID.
     */
    public function setSubscriptionId(string $subscriptionId): self
    {
        $this->document->setAttribute('subscriptionId', $subscriptionId);

        return $this;
    }

    /**
     * Get the entity ID that owns this discount.
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
     * Get the discount type, inherited from the coupon.
     */
    public function getType(): CouponType
    {
        return CouponType::from((string) $this->document->getAttribute('type', 'fixed'));
    }

    /**
     * Set the discount type.
     */
    public function setType(CouponType $type): self
    {
        $this->document->setAttribute('type', $type->value);

        return $this;
    }

    /**
     * Get the discount value (dollar amount for fixed, percentage for percentage type).
     */
    public function getValue(): float
    {
        return (float) $this->document->getAttribute('value', 0.0);
    }

    /**
     * Set the discount value.
     *
     * @param  float  $value  Dollar amount for fixed type, percentage (e.g., 50.0) for percentage type
     */
    public function setValue(float $value): self
    {
        $this->document->setAttribute('value', $value);

        return $this;
    }

    /**
     * Get the discount duration.
     */
    public function getDuration(): CouponDuration
    {
        return CouponDuration::from((string) $this->document->getAttribute('duration', 'once'));
    }

    /**
     * Set the discount duration.
     */
    public function setDuration(CouponDuration $duration): self
    {
        $this->document->setAttribute('duration', $duration->value);

        return $this;
    }

    /**
     * Get the discount scope.
     *
     * Null means invoice-level (applied to subtotal).
     * An array with 'resources' key means line-level (only matching items).
     *
     * @return array<string, mixed>|null
     */
    public function getScope(): ?array
    {
        $scope = $this->document->getAttribute('scope');

        if (\is_string($scope)) {
            return (array) \json_decode($scope, true);
        }

        return $scope;
    }

    /**
     * Set the discount scope.
     *
     * @param  array<string, mixed>|null  $scope  Null for invoice-level, array for line-level
     */
    public function setScope(?array $scope): self
    {
        $this->document->setAttribute('scope', $scope !== null ? \json_encode($scope) : null);

        return $this;
    }

    /**
     * Get the original total number of cycles (null for forever).
     */
    public function getCyclesTotal(): ?int
    {
        return $this->document->getAttribute('cyclesTotal');
    }

    /**
     * Set the original total number of cycles.
     *
     * @param  int|null  $cyclesTotal  Null for forever duration
     */
    public function setCyclesTotal(?int $cyclesTotal): self
    {
        $this->document->setAttribute('cyclesTotal', $cyclesTotal);

        return $this;
    }

    /**
     * Get the remaining number of cycles (null for forever).
     */
    public function getCyclesRemaining(): ?int
    {
        return $this->document->getAttribute('cyclesRemaining');
    }

    /**
     * Set the remaining number of cycles.
     *
     * @param  int|null  $cyclesRemaining  Null for forever duration
     */
    public function setCyclesRemaining(?int $cyclesRemaining): self
    {
        $this->document->setAttribute('cyclesRemaining', $cyclesRemaining);

        return $this;
    }

    /**
     * Get the discount status.
     */
    public function getStatus(): DiscountStatus
    {
        return DiscountStatus::from((string) $this->document->getAttribute('status', 'active'));
    }

    /**
     * Set the discount status.
     */
    public function setStatus(DiscountStatus $status): self
    {
        $this->document->setAttribute('status', $status->value);

        return $this;
    }

    /**
     * Get the datetime when the discount was applied.
     */
    public function getAppliedAt(): string
    {
        return (string) $this->document->getAttribute('appliedAt', '');
    }

    /**
     * Set the datetime when the discount was applied.
     *
     * @param  string  $appliedAt  ISO 8601 datetime string
     */
    public function setAppliedAt(string $appliedAt): self
    {
        $this->document->setAttribute('appliedAt', $appliedAt);

        return $this;
    }

    /**
     * Get the datetime when the discount was exhausted.
     */
    public function getExhaustedAt(): ?string
    {
        return $this->document->getAttribute('exhaustedAt');
    }

    /**
     * Set the datetime when the discount was exhausted.
     *
     * @param  string|null  $exhaustedAt  ISO 8601 datetime string or null
     */
    public function setExhaustedAt(?string $exhaustedAt): self
    {
        $this->document->setAttribute('exhaustedAt', $exhaustedAt);

        return $this;
    }

    /**
     * Get the datetime when the discount was cancelled.
     */
    public function getCancelledAt(): ?string
    {
        return $this->document->getAttribute('cancelledAt');
    }

    /**
     * Set the datetime when the discount was cancelled.
     *
     * @param  string|null  $cancelledAt  ISO 8601 datetime string or null
     */
    public function setCancelledAt(?string $cancelledAt): self
    {
        $this->document->setAttribute('cancelledAt', $cancelledAt);

        return $this;
    }

    /**
     * Get the discount metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $meta = $this->document->getAttribute('metadata', []);

        return \is_string($meta) ? (array) \json_decode($meta, true) : $meta;
    }

    /**
     * Set the discount metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->document->setAttribute('metadata', \json_encode($metadata));

        return $this;
    }

    /**
     * Check if this discount is currently active.
     */
    public function isActive(): bool
    {
        return $this->getStatus() === DiscountStatus::Active;
    }

    /**
     * Check if this discount has been exhausted (all cycles used).
     */
    public function isExhausted(): bool
    {
        return $this->getStatus() === DiscountStatus::Exhausted;
    }

    /**
     * Check if this discount has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->getStatus() === DiscountStatus::Cancelled;
    }

    /**
     * Check if this is an invoice-level discount (scope is null).
     */
    public function isInvoiceLevel(): bool
    {
        return $this->getScope() === null;
    }

    /**
     * Check if this is a line-level discount (scope has resources).
     */
    public function isLineLevel(): bool
    {
        return $this->getScope() !== null;
    }

    /**
     * Get the resource keys this discount applies to (for line-level discounts).
     *
     * @return array<string> List of resource keys, empty if invoice-level
     */
    public function getScopeResources(): array
    {
        $scope = $this->getScope();

        if ($scope === null) {
            return [];
        }

        return $scope['resources'] ?? [];
    }

    /**
     * Check if this is a fixed-type discount.
     */
    public function isFixed(): bool
    {
        return $this->getType() === CouponType::Fixed;
    }

    /**
     * Check if this is a percentage-type discount.
     */
    public function isPercentage(): bool
    {
        return $this->getType() === CouponType::Percentage;
    }
}
