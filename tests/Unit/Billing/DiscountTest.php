<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\CouponDuration;
use Utopia\Billing\CouponType;
use Utopia\Billing\Discount;
use Utopia\Billing\DiscountStatus;
use Utopia\Database\Document;

class DiscountTest extends TestCase
{
    public function testDiscountModel(): void
    {
        $doc = new Document([
            '$id' => 'disc-1',
            'couponId' => 'cpn-1',
            'subscriptionId' => 'sub-1',
            'entityId' => 'entity-1',
            'type' => 'percentage',
            'value' => 10.0,
            'duration' => 'forever',
            'scope' => null,
            'status' => 'active',
            'appliedAt' => '2026-04-01T00:00:00.000+00:00',
            'metadata' => [],
        ]);

        $discount = new Discount($doc);

        $this->assertTrue($discount->isActive());
        $this->assertFalse($discount->isExhausted());
        $this->assertTrue($discount->isInvoiceLevel());
        $this->assertFalse($discount->isLineLevel());
        $this->assertEmpty($discount->getScopeResources());
        $this->assertEquals('discounts', Discount::getName());
    }

    public function testDiscountModelWithScope(): void
    {
        $doc = new Document([
            '$id' => 'disc-2',
            'couponId' => 'cpn-1',
            'subscriptionId' => 'sub-1',
            'entityId' => 'entity-1',
            'type' => 'percentage',
            'value' => 50.0,
            'duration' => 'forever',
            'scope' => ['resources' => ['bandwidth', 'storage']],
            'status' => 'active',
            'appliedAt' => '2026-04-01T00:00:00.000+00:00',
            'metadata' => [],
        ]);

        $discount = new Discount($doc);

        $this->assertTrue($discount->isLineLevel());
        $this->assertFalse($discount->isInvoiceLevel());
        $this->assertEquals(['bandwidth', 'storage'], $discount->getScopeResources());
    }

    public function testDiscountIsFixed(): void
    {
        $doc = new Document(['$id' => 'disc-fixed', 'type' => 'fixed', 'metadata' => '{}']);
        $disc = new Discount($doc);

        $this->assertTrue($disc->isFixed());
        $this->assertFalse($disc->isPercentage());
    }

    public function testDiscountAllSettersGetters(): void
    {
        $doc = new Document(['$id' => 'disc-cov', 'metadata' => '{}']);
        $disc = new Discount($doc);

        $disc->setCouponId('cpn-1');
        $this->assertEquals('cpn-1', $disc->getCouponId());

        $disc->setSubscriptionId('sub-1');
        $this->assertEquals('sub-1', $disc->getSubscriptionId());

        $disc->setEntityId('ent-1');
        $this->assertEquals('ent-1', $disc->getEntityId());

        $disc->setType(CouponType::Percentage);
        $this->assertEquals(CouponType::Percentage, $disc->getType());

        $disc->setValue(15.0);
        $this->assertEquals(15.0, $disc->getValue());

        $disc->setDuration(CouponDuration::Forever);
        $this->assertEquals(CouponDuration::Forever, $disc->getDuration());

        $disc->setScope(['resources' => ['bandwidth']]);
        $this->assertNotNull($disc->getScope());
        $disc->setScope(null);
        $this->assertNull($disc->getScope());

        $disc->setCyclesTotal(3);
        $this->assertEquals(3, $disc->getCyclesTotal());

        $disc->setCyclesRemaining(2);
        $this->assertEquals(2, $disc->getCyclesRemaining());

        $disc->setStatus(DiscountStatus::Active);
        $this->assertEquals(DiscountStatus::Active, $disc->getStatus());

        $disc->setAppliedAt('2026-04-01T00:00:00.000+00:00');
        $this->assertEquals('2026-04-01T00:00:00.000+00:00', $disc->getAppliedAt());

        $disc->setExhaustedAt('2026-07-01T00:00:00.000+00:00');
        $this->assertEquals('2026-07-01T00:00:00.000+00:00', $disc->getExhaustedAt());

        $disc->setCancelledAt('2026-06-01T00:00:00.000+00:00');
        $this->assertEquals('2026-06-01T00:00:00.000+00:00', $disc->getCancelledAt());

        $disc->setMetadata(['reason' => 'promo']);
        $this->assertEquals(['reason' => 'promo'], $disc->getMetadata());

        $this->assertInstanceOf(Document::class, $disc->getDocument());
    }
}
