<?php

declare(strict_types=1);

namespace Utopia\Billing;

/**
 * Credit
 *
 * Static calculation utility class for discount, credit, tax, and proration
 * arithmetic. These are pure functions with no side effects — they perform
 * the billing math that was moved here from the Pay library.
 *
 * All monetary calculations use float and round to 2 decimal places
 * to avoid floating-point drift in invoice totals.
 */
class Credit
{
    /**
     * Apply a percentage discount to an amount.
     *
     * Returns the discounted amount (i.e., the amount AFTER the discount
     * is subtracted). The percentage should be between 0 and 100.
     *
     * @param float $amount     The original amount
     * @param float $percentage The percentage to discount (e.g., 50.0 for 50%)
     *
     * @return float The amount after discount, rounded to 2 decimal places
     *
     * @throws Exception If percentage is negative or greater than 100
     */
    public static function applyPercentageDiscount(float $amount, float $percentage): float
    {
        if ($percentage < 0.0 || $percentage > 100.0) {
            throw new Exception('Percentage must be between 0 and 100');
        }

        $discount = $amount * ($percentage / 100.0);

        return \round($amount - $discount, 2);
    }

    /**
     * Apply a fixed (dollar) discount to an amount.
     *
     * Returns the discounted amount (i.e., the amount AFTER the discount
     * is subtracted). The result will not go below zero.
     *
     * @param float $amount   The original amount
     * @param float $discount The fixed discount amount to subtract
     *
     * @return float The amount after discount, rounded to 2 decimal places (minimum 0.00)
     *
     * @throws Exception If discount is negative
     */
    public static function applyFixedDiscount(float $amount, float $discount): float
    {
        if ($discount < 0.0) {
            throw new Exception('Discount amount must not be negative');
        }

        return \round(\max(0.0, $amount - $discount), 2);
    }

    /**
     * Calculate the tax amount for a given amount and tax rate.
     *
     * Returns only the tax portion (not the total with tax). The rate
     * is a percentage (e.g., 17.0 for 17% VAT).
     *
     * @param float $amount The taxable amount
     * @param float $rate   The tax rate as a percentage (e.g., 17.0 for 17%)
     *
     * @return float The tax amount, rounded to 2 decimal places
     *
     * @throws Exception If rate is negative
     */
    public static function calculateTax(float $amount, float $rate): float
    {
        if ($rate < 0.0) {
            throw new Exception('Tax rate must not be negative');
        }

        return \round($amount * ($rate / 100.0), 2);
    }

    /**
     * Calculate the prorated amount for a partial billing period.
     *
     * Returns the proportional price for the number of days used
     * out of the total days in the billing period.
     *
     * @param float $price     The full-period price
     * @param int   $daysUsed  The number of days consumed
     * @param int   $totalDays The total number of days in the billing period
     *
     * @return float The prorated amount, rounded to 2 decimal places
     *
     * @throws Exception If totalDays is zero or negative, or daysUsed is negative
     */
    public static function calculateProration(float $price, int $daysUsed, int $totalDays): float
    {
        if ($totalDays <= 0) {
            throw new Exception('Total days must be greater than zero');
        }

        if ($daysUsed < 0) {
            throw new Exception('Days used must not be negative');
        }

        return \round($price * ($daysUsed / $totalDays), 2);
    }

    /**
     * Calculate the percentage discount amount (the discount value itself, not the remaining).
     *
     * Useful for generating discount line items on invoices.
     *
     * @param float $amount     The original amount to discount
     * @param float $percentage The percentage discount (e.g., 50.0 for 50%)
     *
     * @return float The discount amount (positive value), rounded to 2 decimal places
     *
     * @throws Exception If percentage is negative or greater than 100
     */
    public static function calculatePercentageDiscountAmount(float $amount, float $percentage): float
    {
        if ($percentage < 0.0 || $percentage > 100.0) {
            throw new Exception('Percentage must be between 0 and 100');
        }

        return \round($amount * ($percentage / 100.0), 2);
    }

    /**
     * Calculate the total with tax included.
     *
     * Returns the original amount plus the calculated tax.
     *
     * @param float $amount The taxable amount
     * @param float $rate   The tax rate as a percentage (e.g., 17.0 for 17%)
     *
     * @return float The total amount including tax, rounded to 2 decimal places
     *
     * @throws Exception If rate is negative
     */
    public static function calculateTotalWithTax(float $amount, float $rate): float
    {
        if ($rate < 0.0) {
            throw new Exception('Tax rate must not be negative');
        }

        $tax = self::calculateTax($amount, $rate);

        return \round($amount + $tax, 2);
    }

    /**
     * Calculate the proration credit for unused days.
     *
     * Returns the credit amount for the remaining unused portion of a
     * billing period (e.g., when downgrading mid-cycle).
     *
     * @param float $price      The full-period price of the current plan
     * @param int   $daysUnused The number of remaining unused days
     * @param int   $totalDays  The total number of days in the billing period
     *
     * @return float The credit amount (positive value), rounded to 2 decimal places
     *
     * @throws Exception If totalDays is zero or negative, or daysUnused is negative
     */
    public static function calculateProrationCredit(float $price, int $daysUnused, int $totalDays): float
    {
        if ($totalDays <= 0) {
            throw new Exception('Total days must be greater than zero');
        }

        if ($daysUnused < 0) {
            throw new Exception('Days unused must not be negative');
        }

        return \round($price * ($daysUnused / $totalDays), 2);
    }
}
