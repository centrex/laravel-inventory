<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Enums\OrderRole;
use Centrex\Inventory\Models\SaleOrder;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors status transitions from the B2B (agent cost) order to the
 * paired B2C (customer) order so both always reflect the same lifecycle.
 *
 * Only the B2B order drives status; the B2C order is a follower.
 */
class PairedOrderObserver
{
    /** Status transitions on the B2B order that should be mirrored to B2C. */
    private const STATUS_MAP = [
        'confirmed'  => 'confirmed',
        'processing' => 'processing',
        'partial'    => 'partial',
        'fulfilled'  => 'fulfilled',
        'cancelled'  => 'cancelled',
        'returned'   => 'returned',
    ];

    public function updated(SaleOrder $saleOrder): void
    {
        if (!$saleOrder->isDirty('status')) {
            return;
        }

        $role = $saleOrder->order_role ?? OrderRole::DIRECT;

        if ($role !== OrderRole::AGENT_B2B) {
            return;
        }

        $pairedId = $saleOrder->paired_sale_order_id;

        if (!$pairedId) {
            return;
        }

        $b2cStatus = self::STATUS_MAP[$saleOrder->status->value] ?? null;

        if ($b2cStatus === null) {
            return;
        }

        // Use a direct DB update with suppressEvents to avoid recursive observer calls
        SaleOrder::withoutTimestamps(function () use ($pairedId, $b2cStatus): void {
            DB::table((new SaleOrder())->getTable())
                ->where('id', $pairedId)
                ->update(['status' => $b2cStatus, 'updated_at' => now()]);
        });
    }
}
