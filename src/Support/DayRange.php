<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Half-open day bounds for filtering `timestamp` columns by calendar date.
 *
 * Filtering a timestamp column with whereDate() is correct but not sargable — it
 * compiles to `date(ordered_at) >= ?`, which wraps the column in a function and so
 * discards any index on it, forcing a full scan of inv_sale_orders / inv_purchase_orders
 * on every report and dashboard query.
 *
 * Comparing against raw bounds instead keeps the index usable. The upper bound has to
 * be exclusive-next-midnight rather than the end date itself, because `ordered_at <=
 * '2025-04-25'` would exclude everything actually recorded during that day (it reads as
 * `<= '2025-04-25 00:00:00'`). `until()` therefore pairs with `<`, never `<=`.
 *
 *   ->where('ordered_at', '>=', DayRange::from($startDate))
 *   ->where('ordered_at', '<',  DayRange::until($endDate))
 */
final class DayRange
{
    /** Inclusive lower bound: midnight at the start of $date. */
    public static function from(mixed $date): Carbon
    {
        return self::parse($date)->startOfDay();
    }

    /** Exclusive upper bound: midnight at the start of the day *after* $date. Pair with `<`. */
    public static function until(mixed $date): Carbon
    {
        return self::parse($date)->startOfDay()->addDay();
    }

    private static function parse(mixed $date): Carbon
    {
        if ($date instanceof CarbonInterface) {
            return Carbon::instance($date);
        }

        return Carbon::parse(is_scalar($date) ? (string) $date : null);
    }
}
