<?php

declare(strict_types=1);

namespace Utopia\Billing;

use DateTime;

/**
 * Period
 *
 * Value object representing a billing period with a start and end date.
 * Used for subscription billing cycles, trial periods, and proration calculations.
 */
class Period
{
    /**
     * Period constructor.
     *
     * @param  DateTime  $start  The start of the period (inclusive)
     * @param  DateTime  $end  The end of the period (inclusive)
     *
     * @throws Exception If start is after end
     */
    public function __construct(
        protected DateTime $start,
        protected DateTime $end,
    ) {
        if ($start > $end) {
            throw new Exception('Period start must be before or equal to end');
        }
    }

    /**
     * Get the start date of the period.
     */
    public function getStart(): DateTime
    {
        return $this->start;
    }

    /**
     * Get the end date of the period.
     */
    public function getEnd(): DateTime
    {
        return $this->end;
    }

    /**
     * Get the number of days in the period.
     */
    public function getDays(): int
    {
        $diff = $this->start->diff($this->end);

        return (int) $diff->days;
    }

    /**
     * Check if a given date falls within the period (inclusive).
     *
     * @param  DateTime  $date  The date to check
     */
    public function contains(DateTime $date): bool
    {
        return $date >= $this->start && $date <= $this->end;
    }

    /**
     * Check if this period overlaps with another period.
     *
     * Two periods overlap if one starts before the other ends and vice versa.
     *
     * @param  Period  $other  The other period to check against
     */
    public function overlaps(Period $other): bool
    {
        return $this->start <= $other->getEnd() && $this->end >= $other->getStart();
    }

    /**
     * Check if the period has expired relative to the current time or a given date.
     *
     * @param  DateTime|null  $now  The reference date (defaults to current time)
     */
    public function isExpired(?DateTime $now = null): bool
    {
        $now = $now ?? new DateTime();

        return $this->end < $now;
    }
}
