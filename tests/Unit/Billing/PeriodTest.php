<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use DateTime;
use PHPUnit\Framework\TestCase;
use Utopia\Billing\Exception;
use Utopia\Billing\Period;

class PeriodTest extends TestCase
{
    public function test_period_value_object(): void
    {
        $start = new DateTime('2026-04-01');
        $end = new DateTime('2026-04-30');
        $period = new Period($start, $end);

        $this->assertEquals(29, $period->getDays());
        $this->assertTrue($period->contains(new DateTime('2026-04-15')));
        $this->assertFalse($period->contains(new DateTime('2026-05-01')));
        $this->assertTrue($period->isExpired(new DateTime('2026-05-01')));
        $this->assertFalse($period->isExpired(new DateTime('2026-04-15')));
    }

    public function test_period_invalid_range(): void
    {
        $this->expectException(Exception::class);
        new Period(new DateTime('2026-04-30'), new DateTime('2026-04-01'));
    }

    public function test_period_overlap(): void
    {
        $p1 = new Period(new DateTime('2026-04-01'), new DateTime('2026-04-15'));
        $p2 = new Period(new DateTime('2026-04-10'), new DateTime('2026-04-25'));
        $p3 = new Period(new DateTime('2026-04-16'), new DateTime('2026-04-30'));

        $this->assertTrue($p1->overlaps($p2));
        $this->assertFalse($p1->overlaps($p3));
    }

    public function test_period_same_start_and_end(): void
    {
        $date = new DateTime('2026-04-15');
        $period = new Period($date, clone $date);

        $this->assertEquals(0, $period->getDays());
        $this->assertTrue($period->contains(clone $date));
    }

    public function test_period_contains_boundary_dates(): void
    {
        $start = new DateTime('2026-04-01');
        $end = new DateTime('2026-04-30');
        $period = new Period($start, $end);

        $this->assertTrue($period->contains(new DateTime('2026-04-01'))); // start inclusive
        $this->assertTrue($period->contains(new DateTime('2026-04-30'))); // end inclusive
        $this->assertFalse($period->contains(new DateTime('2026-03-31')));
        $this->assertFalse($period->contains(new DateTime('2026-05-01')));
    }

    public function test_period_is_expired_with_custom_date(): void
    {
        $period = new Period(new DateTime('2026-04-01'), new DateTime('2026-04-30'));

        $this->assertTrue($period->isExpired(new DateTime('2026-05-15')));
        $this->assertFalse($period->isExpired(new DateTime('2026-04-15')));
        $this->assertFalse($period->isExpired(new DateTime('2026-04-30'))); // end date is not expired yet
    }

    public function test_period_overlap_adjacent(): void
    {
        $p1 = new Period(new DateTime('2026-04-01'), new DateTime('2026-04-15'));
        $p2 = new Period(new DateTime('2026-04-15'), new DateTime('2026-04-30'));

        // Adjacent periods (share boundary) DO overlap per the >= logic
        $this->assertTrue($p1->overlaps($p2));
    }
}
