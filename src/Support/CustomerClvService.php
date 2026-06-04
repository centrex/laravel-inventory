<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\{Customer, SaleOrder};
use Illuminate\Support\Collection;

/**
 * Optional BTYD integration. All methods silently return empty/null values
 * when centrex/laravel-btyd is not installed or the model has not been fitted yet.
 */
class CustomerClvService
{
    public static function isAvailable(): bool
    {
        return class_exists(\Centrex\Btyd\Btyd::class)
            && class_exists(\Centrex\Btyd\Models\BtydParam::class)
            && \Centrex\Btyd\Models\BtydParam::isFitted();
    }

    /**
     * Compute CLV metrics for a collection of Customer models.
     * Returns a flat array keyed by customer_id:
     *   ['clv_12m' => float, 'prob_alive' => float, 'expected_tx' => float]
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<int, array{clv_12m: float, prob_alive: float, expected_tx: float}>
     */
    public static function computeForCustomers(Collection $customers, int $horizonMonths = 12): array
    {
        if (!self::isAvailable() || $customers->isEmpty()) {
            return [];
        }

        $btyd = app(\Centrex\Btyd\Btyd::class)->loadFromDb();

        if (!$btyd->isReady()) {
            return [];
        }

        $ids = $customers->pluck('id')->all();

        // Load all relevant orders in one query and group by customer.
        $ordersByCustomer = SaleOrder::query()
            ->whereIn('customer_id', $ids)
            ->where('document_type', 'order')
            ->whereNotNull('ordered_at')
            ->orderBy('ordered_at')
            ->select(['customer_id', 'ordered_at', 'total_local'])
            ->get()
            ->groupBy('customer_id');

        $result = [];

        foreach ($ids as $customerId) {
            $txs = ($ordersByCustomer[$customerId] ?? collect())
                ->map(fn ($o): array => [
                    'date'   => $o->ordered_at,
                    'amount' => (float) $o->total_local,
                ])
                ->all();

            $summary = \Centrex\Btyd\Btyd::transactionsToSummary($txs);

            $result[$customerId] = [
                'clv_' . $horizonMonths . 'm' => $btyd->customerClv($summary, $horizonMonths),
                'prob_alive'                   => round($btyd->probabilityAlive($summary), 4),
                'expected_tx'                  => round($btyd->expectedTransactions($summary, $horizonMonths), 2),
            ];
        }

        return $result;
    }
}
