<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Commands;

use Centrex\Inventory\Enums\MovementType;
use Centrex\Inventory\Models\{StockMovement, WarehouseProduct};
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-off backfill for warehouse/product rows that carry an on-hand quantity but have
 * no stock-movement audit trail — e.g. stock seeded/imported directly into
 * inv_warehouse_products, or rows created before movement tracking existed. Writes a
 * single OPENING_STOCK movement per affected row so getMovementHistory()/reports have
 * a starting point to reconcile against, instead of an unexplained on-hand balance.
 */
class BackfillStockMovementsCommand extends Command
{
    public $signature = 'inventory:backfill-movements
        {--warehouse= : Restrict to a single warehouse ID}
        {--as-of= : Timestamp recorded on backfilled movements (YYYY-MM-DD [HH:MM:SS]). Defaults to now.}
        {--dry-run : Preview what would be created without writing anything}';

    public $description = 'Backfill an opening-stock movement for warehouse/product rows that have on-hand quantity but no movement history.';

    public function handle(): int
    {
        $movedAt = $this->resolveAsOf();

        if ($movedAt === null) {
            return self::FAILURE;
        }

        $warehouseId = $this->option('warehouse') !== null ? (int) $this->option('warehouse') : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = WarehouseProduct::query()
            ->where('qty_on_hand', '<>', 0)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No warehouse/product rows with a non-zero on-hand quantity found.');

            return self::SUCCESS;
        }

        $this->info("Scanning {$total} warehouse/product row(s) for missing movement history…");

        $backfilled = 0;
        $skippedHasHistory = 0;
        $skippedNegative = 0;

        $query->orderBy('id')->chunkById(200, function ($chunk) use (&$backfilled, &$skippedHasHistory, &$skippedNegative, $dryRun, $movedAt): void {
            foreach ($chunk as $warehouseProduct) {
                /** @var WarehouseProduct $warehouseProduct */
                $hasHistory = StockMovement::query()
                    ->where('warehouse_id', $warehouseProduct->warehouse_id)
                    ->where('product_id', $warehouseProduct->product_id)
                    ->where('variant_id', $warehouseProduct->variant_id)
                    ->exists();

                if ($hasHistory) {
                    $skippedHasHistory++;

                    continue;
                }

                $qty = (float) $warehouseProduct->qty_on_hand;

                if ($qty < 0) {
                    $skippedNegative++;
                    $this->warn("Skipping warehouse_product #{$warehouseProduct->id}: negative on-hand qty ({$qty}) can't be backfilled as opening stock.");

                    continue;
                }

                $backfilled++;

                if ($dryRun) {
                    $this->line("[dry run] warehouse_product #{$warehouseProduct->id} (warehouse={$warehouseProduct->warehouse_id}, product={$warehouseProduct->product_id}): opening stock {$qty}.");

                    continue;
                }

                StockMovement::create([
                    'warehouse_id'     => $warehouseProduct->warehouse_id,
                    'product_id'       => $warehouseProduct->product_id,
                    'variant_id'       => $warehouseProduct->variant_id,
                    'movement_type'    => MovementType::OPENING_STOCK,
                    'direction'        => MovementType::OPENING_STOCK->direction(),
                    'qty'              => $qty,
                    'qty_before'       => 0,
                    'qty_after'        => $qty,
                    'unit_cost_amount' => $warehouseProduct->wac_amount,
                    'wac_amount'       => $warehouseProduct->wac_amount,
                    'notes'            => 'Backfilled opening stock (no prior movement history).',
                    'moved_at'         => $movedAt,
                ]);
            }
        });

        $verb = $dryRun ? 'Would backfill' : 'Backfilled';
        $this->info("{$verb} {$backfilled} opening-stock movement(s); {$skippedHasHistory} already had history; {$skippedNegative} skipped (negative qty).");

        return self::SUCCESS;
    }

    private function resolveAsOf(): ?Carbon
    {
        $raw = $this->option('as-of');

        if ($raw === null) {
            return Carbon::now();
        }

        try {
            return Carbon::parse((string) $raw);
        } catch (\Throwable) {
            $this->error("Invalid --as-of value: {$raw}");

            return null;
        }
    }
}
