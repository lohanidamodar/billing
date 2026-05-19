<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\Coupon;
use Utopia\Billing\CouponDuration;
use Utopia\Billing\CouponType;
use Utopia\Database\Document;

class CouponTest extends TestCase
{
    public function testCouponModel(): void
    {
        $doc = new Document([
            '$id' => 'cpn-1',
            'code' => 'TEST',
            'type' => 'percentage',
            'value' => 20.0,
            'duration' => 'repeating',
            'durationInCycles' => 3,
            'timesRedeemed' => 0,
            'active' => true,
            'metadata' => [],
        ]);

        $coupon = new Coupon($doc);

        $this->assertTrue($coupon->isPercentage());
        $this->assertFalse($coupon->isFixed());
        $this->assertTrue($coupon->isRedeemable());
        $this->assertEquals('coupons', Coupon::getName());
    }

    public function testCouponAllSettersGetters(): void
    {
        $doc = new Document(['$id' => 'cpn-cov', 'metadata' => '{}']);
        $cpn = new Coupon($doc);

        $cpn->setCode('TEST');
        $this->assertEquals('TEST', $cpn->getCode());

        $cpn->setType(CouponType::Fixed);
        $this->assertEquals(CouponType::Fixed, $cpn->getType());
        $this->assertTrue($cpn->isFixed());
        $this->assertFalse($cpn->isPercentage());

        $cpn->setValue(25.0);
        $this->assertEquals(25.0, $cpn->getValue());

        $cpn->setCurrency('EUR');
        $this->assertEquals('EUR', $cpn->getCurrency());

        $cpn->setDuration(CouponDuration::Repeating);
        $this->assertEquals(CouponDuration::Repeating, $cpn->getDuration());

        $cpn->setDurationInCycles(5);
        $this->assertEquals(5, $cpn->getDurationInCycles());

        $cpn->setMaxRedemptions(100);
        $this->assertEquals(100, $cpn->getMaxRedemptions());

        $cpn->setTimesRedeemed(10);
        $this->assertEquals(10, $cpn->getTimesRedeemed());

        $cpn->setExpiresAt('2027-01-01T00:00:00.000+00:00');
        $this->assertEquals('2027-01-01T00:00:00.000+00:00', $cpn->getExpiresAt());

        $cpn->setScope(['planIds' => ['plan-a']]);
        $this->assertEquals(['planIds' => ['plan-a']], $cpn->getScope());
        $cpn->setScope(null);
        $this->assertNull($cpn->getScope());

        $cpn->setActive(true);
        $this->assertTrue($cpn->isActive());

        $cpn->setMetadata(['promo' => true]);
        $this->assertEquals(['promo' => true], $cpn->getMetadata());

        $this->assertInstanceOf(Document::class, $cpn->getDocument());
    }
}
