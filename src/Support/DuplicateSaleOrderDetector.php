<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\SaleOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds a very-recently-created sale order with the exact same warehouse/customer/document-type/
 * item set as one about to be submitted — used to make repeat submissions (double-click, a
 * client retrying after a timeout, a form resubmitted after a reload) idempotent instead of
 * creating genuine duplicates.
 *
 * Content-based rather than request-identity-based (unlike GuardsAgainstDuplicateSubmission's
 * in-flight lock, which is keyed to one page load's form_token and sees nothing once that
 * request finishes), so this catches the far more common real-world case: a caller that gives
 * up waiting and retries minutes — or, for a timeout-driven retry loop, seconds — later.
 */
class DuplicateSaleOrderDetector
{
    public function find(
        int $warehouseId,
        ?int $customerId,
        string $documentType,
        array $items,
        ?int $createdBy = null,
        ?int $windowMinutes = null,
    ): ?SaleOrder {
        $signature = $this->signature($items);
        $windowMinutes ??= (int) config('inventory.duplicate_order_warning_window_minutes', 5);

        $candidates = SaleOrder::query()
            ->where('warehouse_id', $warehouseId)
            ->where('document_type', $documentType)
            ->where('customer_id', $customerId)
            ->when($createdBy !== null, fn (Builder $query): Builder => $query->where('created_by', $createdBy))
            ->where('status', '!=', SaleOrderStatus::CANCELLED->value)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->with('items')
            ->latest('id')
            ->limit(5)
            ->get();

        return $candidates->first(
            fn (SaleOrder $order): bool => $this->signature($order->items->map(fn ($item): array => [
                'product_id'  => $item->product_id,
                'variant_id'  => $item->variant_id,
                'qty_ordered' => $item->qty_ordered,
            ])->all()) === $signature,
        );
    }

    /** Order-independent fingerprint of an item set, for comparing two submissions' contents. */
    public function signature(array $items): string
    {
        return collect($items)
            ->map(fn (array $item): string => sprintf(
                '%d:%d:%s',
                (int) $item['product_id'],
                (int) ($item['variant_id'] ?? 0),
                rtrim(rtrim(number_format((float) $item['qty_ordered'], 4, '.', ''), '0'), '.'),
            ))
            ->sort()
            ->values()
            ->implode('|');
    }
}
