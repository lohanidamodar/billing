<?php

declare(strict_types=1);

namespace Utopia\Billing;

use Utopia\Database\Document;

/**
 * Coupon
 *
 * Document wrapper for the coupons collection. A coupon is a reusable
 * template/definition for discounts. It does not track application state;
 * that is handled by the Discount model.
 */
class Coupon
{
    /**
     * Coupon constructor.
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
     * Get the collection name for coupons.
     */
    public static function getName(): string
    {
        return 'coupons';
    }

    /**
     * Get the coupon ID.
     */
    public function getId(): string
    {
        return $this->document->getId();
    }

    /**
     * Get the user-facing coupon code.
     */
    public function getCode(): string
    {
        return (string) $this->document->getAttribute('code', '');
    }

    /**
     * Set the user-facing coupon code.
     */
    public function setCode(string $code): self
    {
        $this->document->setAttribute('code', $code);

        return $this;
    }

    /**
     * Get the coupon type.
     */
    public function getType(): CouponType
    {
        return CouponType::from((string) $this->document->getAttribute('type', 'fixed'));
    }

    /**
     * Set the coupon type.
     */
    public function setType(CouponType $type): self
    {
        $this->document->setAttribute('type', $type->value);

        return $this;
    }

    /**
     * Get the coupon value (dollar amount for fixed, percentage for percentage type).
     */
    public function getValue(): float
    {
        return (float) $this->document->getAttribute('value', 0.0);
    }

    /**
     * Set the coupon value.
     *
     * @param  float  $value  Dollar amount for fixed type, percentage (e.g., 50.0) for percentage type
     */
    public function setValue(float $value): self
    {
        $this->document->setAttribute('value', $value);

        return $this;
    }

    /**
     * Get the currency for fixed-type coupons.
     */
    public function getCurrency(): ?string
    {
        return $this->document->getAttribute('currency');
    }

    /**
     * Set the currency for fixed-type coupons.
     *
     * @param  string|null  $currency  ISO 4217 currency code or null
     */
    public function setCurrency(?string $currency): self
    {
        $this->document->setAttribute('currency', $currency);

        return $this;
    }

    /**
     * Get the coupon duration.
     */
    public function getDuration(): CouponDuration
    {
        return CouponDuration::from((string) $this->document->getAttribute('duration', 'once'));
    }

    /**
     * Set the coupon duration.
     */
    public function setDuration(CouponDuration $duration): self
    {
        $this->document->setAttribute('duration', $duration->value);

        return $this;
    }

    /**
     * Get the number of billing cycles for 'repeating' duration coupons.
     *
     * @return int|null Null for 'once' and 'forever' durations
     */
    public function getDurationInCycles(): ?int
    {
        return $this->document->getAttribute('durationInCycles');
    }

    /**
     * Set the number of billing cycles for 'repeating' duration coupons.
     */
    public function setDurationInCycles(?int $durationInCycles): self
    {
        $this->document->setAttribute('durationInCycles', $durationInCycles);

        return $this;
    }

    /**
     * Get the maximum number of total redemptions allowed.
     *
     * @return int|null Null means unlimited
     */
    public function getMaxRedemptions(): ?int
    {
        return $this->document->getAttribute('maxRedemptions');
    }

    /**
     * Set the maximum number of total redemptions allowed.
     *
     * @param  int|null  $maxRedemptions  Null for unlimited
     */
    public function setMaxRedemptions(?int $maxRedemptions): self
    {
        $this->document->setAttribute('maxRedemptions', $maxRedemptions);

        return $this;
    }

    /**
     * Get the number of times this coupon has been redeemed.
     */
    public function getTimesRedeemed(): int
    {
        return (int) $this->document->getAttribute('timesRedeemed', 0);
    }

    /**
     * Set the number of times this coupon has been redeemed.
     */
    public function setTimesRedeemed(int $timesRedeemed): self
    {
        $this->document->setAttribute('timesRedeemed', $timesRedeemed);

        return $this;
    }

    /**
     * Get the coupon template expiry datetime.
     */
    public function getExpiresAt(): ?string
    {
        return $this->document->getAttribute('expiresAt');
    }

    /**
     * Set the coupon template expiry datetime.
     *
     * @param  string|null  $expiresAt  ISO 8601 datetime string or null
     */
    public function setExpiresAt(?string $expiresAt): self
    {
        $this->document->setAttribute('expiresAt', $expiresAt);

        return $this;
    }

    /**
     * Get the coupon scope (plan IDs, resource types it applies to).
     *
     * @return array<string, mixed>|null Null means applies to everything
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
     * Set the coupon scope.
     *
     * @param  array<string, mixed>|null  $scope  Null means applies to everything
     */
    public function setScope(?array $scope): self
    {
        $this->document->setAttribute('scope', $scope !== null ? \json_encode($scope) : null);

        return $this;
    }

    /**
     * Check if the coupon is active.
     */
    public function isActive(): bool
    {
        return (bool) $this->document->getAttribute('active', false);
    }

    /**
     * Set the coupon active status.
     */
    public function setActive(bool $active): self
    {
        $this->document->setAttribute('active', $active);

        return $this;
    }

    /**
     * Get the coupon metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $meta = $this->document->getAttribute('metadata', []);

        return \is_string($meta) ? (array) \json_decode($meta, true) : $meta;
    }

    /**
     * Set the coupon metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->document->setAttribute('metadata', \json_encode($metadata));

        return $this;
    }

    /**
     * Check if the coupon can still be redeemed (active, not expired, under max redemptions).
     */
    public function isRedeemable(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $expiresAt = $this->getExpiresAt();
        if ($expiresAt !== null && new \DateTime($expiresAt) < new \DateTime) {
            return false;
        }

        $maxRedemptions = $this->getMaxRedemptions();
        if ($maxRedemptions !== null && $this->getTimesRedeemed() >= $maxRedemptions) {
            return false;
        }

        return true;
    }

    /**
     * Check if this is a fixed-type coupon.
     */
    public function isFixed(): bool
    {
        return $this->getType() === CouponType::Fixed;
    }

    /**
     * Check if this is a percentage-type coupon.
     */
    public function isPercentage(): bool
    {
        return $this->getType() === CouponType::Percentage;
    }
}
