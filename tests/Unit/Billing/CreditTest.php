<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Utopia\Billing\Credit;
use Utopia\Billing\Exception;

class CreditTest extends TestCase
{
    public function test_credit_calculations(): void
    {
        // Percentage discount
        $this->assertEquals(90.0, Credit::applyPercentageDiscount(100.0, 10.0));
        $this->assertEquals(50.0, Credit::applyPercentageDiscount(100.0, 50.0));
        $this->assertEquals(0.0, Credit::applyPercentageDiscount(100.0, 100.0));

        // Fixed discount
        $this->assertEquals(80.0, Credit::applyFixedDiscount(100.0, 20.0));
        $this->assertEquals(0.0, Credit::applyFixedDiscount(10.0, 20.0)); // Can't go below zero

        // Tax
        $this->assertEquals(17.0, Credit::calculateTax(100.0, 17.0));
        $this->assertEquals(0.0, Credit::calculateTax(100.0, 0.0));

        // Proration
        $this->assertEquals(15.0, Credit::calculateProration(30.0, 15, 30));
        $this->assertEquals(10.0, Credit::calculateProration(30.0, 10, 30));

        // Total with tax
        $this->assertEquals(117.0, Credit::calculateTotalWithTax(100.0, 17.0));

        // Percentage discount amount
        $this->assertEquals(10.0, Credit::calculatePercentageDiscountAmount(100.0, 10.0));

        // Proration credit
        $this->assertEquals(20.0, Credit::calculateProrationCredit(30.0, 20, 30));
    }

    public function test_credit_invalid_percentage(): void
    {
        $this->expectException(Exception::class);
        Credit::applyPercentageDiscount(100.0, 101.0);
    }

    public function test_credit_invalid_fixed_discount(): void
    {
        $this->expectException(Exception::class);
        Credit::applyFixedDiscount(100.0, -5.0);
    }

    public function test_credit_invalid_tax_rate(): void
    {
        $this->expectException(Exception::class);
        Credit::calculateTax(100.0, -1.0);
    }

    public function test_credit_invalid_proration(): void
    {
        $this->expectException(Exception::class);
        Credit::calculateProration(30.0, 10, 0);
    }

    public function test_credit_zero_percent_discount(): void
    {
        $this->assertEquals(100.0, Credit::applyPercentageDiscount(100.0, 0.0));
    }

    public function test_credit_negative_percentage(): void
    {
        $this->expectException(Exception::class);
        Credit::applyPercentageDiscount(100.0, -1.0);
    }

    public function test_credit_proration_zero_days(): void
    {
        $this->assertEquals(0.0, Credit::calculateProration(30.0, 0, 30));
    }

    public function test_credit_proration_negative_days(): void
    {
        $this->expectException(Exception::class);
        Credit::calculateProration(30.0, -1, 30);
    }

    public function test_credit_proration_credit_negative_days(): void
    {
        $this->expectException(Exception::class);
        Credit::calculateProrationCredit(30.0, -1, 30);
    }

    public function test_credit_proration_credit_zero_total(): void
    {
        $this->expectException(Exception::class);
        Credit::calculateProrationCredit(30.0, 10, 0);
    }

    public function test_credit_total_with_tax_negative_rate(): void
    {
        $this->expectException(Exception::class);
        Credit::calculateTotalWithTax(100.0, -5.0);
    }

    public function test_credit_percentage_discount_amount_invalid(): void
    {
        $this->expectException(Exception::class);
        Credit::calculatePercentageDiscountAmount(100.0, 150.0);
    }
}
