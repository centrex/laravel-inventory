<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\MovementType;
use Centrex\Inventory\Models\{Product, ProductVariant, StockMovement, WarehouseProduct};
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Internal write-side infrastructure used by nearly every other domain trait: WAC
 * recalculation, the append-only movement ledger, sequential document numbering
 * (with a lock-and-retry collision guard), row-locked stock lookups, and small
 * validation/labelling helpers.
 */
trait HasSharedInventoryHelpers
{
    /**
     * Memoised `inventory.qty_tolerance`, keyed by application instance.
     *
     * The tolerance is consulted on nearly every quantity comparison, including inside the
     * per-line loops of the receipt, fulfilment, transfer and adjustment paths — so it was
     * being re-read from config dozens of times per posted document, each read a container
     * resolve plus a dotted-key lookup. Keying by container identity keeps a rebuilt
     * application (Testbench, Octane) from inheriting the previous one's value.
     *
     * @var array<int, float>
     */
    private static array $qtyTolerances = [];

    /** Quantity comparison tolerance, used to absorb float rounding on decimal quantities. */
    private function qtyTolerance(): float
    {
        return self::$qtyTolerances[spl_object_id(app())] ??= (float) config('inventory.qty_tolerance', 0.0001);
    }

    /**
     * Recalculate the weighted-average cost after receiving $qtyIn units at $unitCostAmount each.
     *
     * Formula: (currentQty × currentWAC + qtyIn × unitCost) / (currentQty + qtyIn)
     * When the bin is empty the new unit cost becomes the WAC directly.
     */
    private function recalculateWac(WarehouseProduct $wp, float $qtyIn, float $unitCostAmount): float
    {
        $currentQty = (float) $wp->qty_on_hand;
        $currentWac = (float) $wp->wac_amount;

        if ($currentQty <= 0) {
            return round($unitCostAmount, (int) config('inventory.wac_precision', 4));
        }

        $newWac = (($currentQty * $currentWac) + ($qtyIn * $unitCostAmount)) / ($currentQty + $qtyIn);

        return round($newWac, (int) config('inventory.wac_precision', 4));
    }

    /** Append an immutable stock-movement audit row to inv_stock_movements. */
    private function writeMovement(int $warehouseId, int $productId, ?int $variantId, MovementType $type, float $qty, float $qtyBefore, float $qtyAfter, ?float $unitCostAmount, ?float $wacAmount, ?string $refType, ?int $refId, ?int $createdBy = null, ?string $notes = null, ?int $lotId = null): StockMovement
    {
        return StockMovement::create([
            'warehouse_id'     => $warehouseId,
            'product_id'       => $productId,
            'variant_id'       => $variantId,
            'lot_id'           => $lotId,
            'movement_type'    => $type,
            'direction'        => $type->direction(),
            'qty'              => $qty,
            'qty_before'       => $qtyBefore,
            'qty_after'        => $qtyAfter,
            'unit_cost_amount' => $unitCostAmount,
            'wac_amount'       => $wacAmount,
            'reference_type'   => $refType,
            'reference_id'     => $refId,
            'notes'            => $notes,
            'moved_at'         => now(),
            'created_by'       => $createdBy,
        ]);
    }

    /**
     * Generate the next sequential document number in the format PREFIX-YYYYMMDD-NNNN.
     *
     * Example: PO-20250610-0003 — the last four digits reset is NOT date-scoped; the counter
     * is derived from the highest existing number matching the prefix so it always increments.
     *
     * The LIKE pattern is width-constrained (8 underscores for the date, 4 for the sequence),
     * not just "{prefix}-%" — some tables carry legacy/imported numbers that also start with
     * the same prefix but in a totally different shape (e.g. "GRN-PO000955" instead of
     * "GRN-20260728-0001"). Those legacy strings sort ABOVE every possible date-based number
     * lexicographically ('P' > any digit in ASCII), so an unconstrained "{prefix}-%" match
     * would let one make ORDER BY ... DESC permanently return that legacy row as "latest" —
     * deterministically wrong on every call, not just a rare race window. Underscore wildcards
     * are standard SQL (unlike REGEXP, which isn't portable to the sqlite connection this
     * package's own test suite runs against), so this keeps the DB-agnostic behavior intact.
     */
    private function nextNumber(string $prefix, string $model, string $column): string
    {
        $today = now()->format('Ymd');

        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true)
            ? $model::withTrashed()
            : $model::query();

        $shapePattern = "{$prefix}-" . str_repeat('_', 8) . '-' . str_repeat('_', 4);

        $latest = $query
            ->where($column, 'like', $shapePattern)
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        $count = $latest
            ? ((int) substr((string) $latest, -4)) + 1
            : 1;

        return "{$prefix}-{$today}-" . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Creates a model with a nextNumber()-generated document number, retrying with a freshly
     * generated number if a concurrent request already claimed the one we picked.
     *
     * nextNumber()'s "lock the current max row" approach can't guarantee uniqueness when the
     * locking read matches zero rows at the exact moment two requests race (nothing exists yet
     * to lock) — this converts that rare race into a transparent retry instead of surfacing a
     * duplicate-key 500 to the user. Only retries on a violation of the number column's own
     * unique index, so an unrelated unique-constraint failure on the same insert still fails fast.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes  Every column except $column.
     * @return TModel
     */
    private function createWithSequentialNumber(string $prefix, string $model, string $column, array $attributes, int $maxAttempts = 5): Model
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                /** @var TModel $record */
                $record = $model::create([$column => $this->nextNumber($prefix, $model, $column), ...$attributes]);

                return $record;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= $maxAttempts || !str_contains($exception->getMessage(), "{$column}_unique")) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException("Failed to generate a unique {$column} after {$maxAttempts} attempts.");
    }

    /**
     * Pessimistic-lock the WarehouseProduct row for the duration of the current transaction.
     * Creates the row if it does not exist yet (INSERT OR IGNORE, then re-select with lock).
     */
    private function lockWarehouseProduct(int $warehouseId, int $productId, ?int $variantId = null): WarehouseProduct
    {
        $model = new WarehouseProduct();
        $variantId = $this->normalizeVariantId($variantId, $productId);

        $existing = WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        DB::connection($model->getConnectionName())->table($model->getTable())->insertOrIgnore([
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'variant_id'     => $variantId,
            'qty_on_hand'    => 0,
            'qty_reserved'   => 0,
            'qty_in_transit' => 0,
            'wac_amount'     => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Validate that $variantId belongs to $productId and return it as int|null.
     * Accepts empty-string as null so HTML form values work without extra casting.
     */
    private function normalizeVariantId(null|int|string $variantId, int $productId): ?int
    {
        if ($variantId === null || $variantId === '') {
            return null;
        }

        $variant = ProductVariant::query()->findOrFail((int) $variantId);

        if ((int) $variant->product_id !== $productId) {
            throw new \InvalidArgumentException("Variant [{$variant->getKey()}] does not belong to product [{$productId}].");
        }

        return (int) $variant->getKey();
    }

    private function resolveProductReference(array $item): array
    {
        $productId = (int) $item['product_id'];
        $variantId = $this->normalizeVariantId($item['variant_id'] ?? null, $productId);

        return [$productId, $variantId];
    }

    private function productLabel(int $productId, ?int $variantId = null): string
    {
        $product = Product::query()->find($productId);

        if ($variantId === null) {
            return $product?->display_name ?? ('#' . $productId);
        }

        $variant = ProductVariant::query()->find($variantId);

        return $variant?->display_name ?? (($product?->display_name ?? ('#' . $productId)) . ' / #' . $variantId);
    }

    private function ensurePositiveQuantity(float $qty, string $field): void
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException("{$field} must be greater than zero.");
        }
    }

    private function erp(): ErpIntegration
    {
        return app(ErpIntegration::class);
    }
}
