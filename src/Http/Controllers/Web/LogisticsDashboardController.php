<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Models\{PurchaseOrder, SaleOrder, Shipment, StockReceipt, Transfer, WarehouseProduct};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class LogisticsDashboardController
{
    public function __invoke(): View
    {
        Gate::authorize('inventory.logistics.view');

        $transfers = Transfer::query()->with(['fromWarehouse', 'toWarehouse'])->latest('id')->limit(8)->get();
        $shipments = Shipment::query()->with(['fromWarehouse', 'toWarehouse'])->latest('id')->limit(8)->get();
        $receipts = StockReceipt::query()->with(['warehouse', 'purchaseOrder'])->latest('received_at')->limit(8)->get();
        $openPurchases = PurchaseOrder::query()
            ->with(['supplier', 'warehouse'])
            ->where('document_type', 'order')
            ->whereIn('status', ['submitted', 'confirmed', 'partial'])
            ->latest('expected_at')
            ->limit(8)
            ->get();
        $fulfillmentQueue = SaleOrder::query()
            ->with(['customer', 'warehouse'])
            ->where('document_type', 'order')
            ->whereIn('status', ['confirmed', 'processing', 'partial'])
            ->latest('ordered_at')
            ->limit(8)
            ->get();
        $lowStock = WarehouseProduct::query()
            ->with(['warehouse', 'product'])
            ->whereNotNull('reorder_point')
            ->whereRaw('(qty_on_hand - qty_reserved) <= reorder_point')
            ->limit(10)
            ->get();

        return view('inventory::logistics.dashboard', [
            'transfers'        => $transfers,
            'shipments'        => $shipments,
            'receipts'         => $receipts,
            'openPurchases'    => $openPurchases,
            'fulfillmentQueue' => $fulfillmentQueue,
            'lowStock'         => $lowStock,
            'metrics'          => [
                'open_transfers'   => Transfer::query()->whereIn('status', ['draft', 'in_transit', 'partial'])->count(),
                'open_shipments'   => Shipment::query()->whereIn('status', ['draft', 'in_transit', 'partial'])->count(),
                'pending_receipts' => StockReceipt::query()->where('status', 'draft')->count(),
                'open_purchases'   => PurchaseOrder::query()->where('document_type', 'order')->whereIn('status', ['submitted', 'confirmed', 'partial'])->count(),
                'fulfillment_due'  => SaleOrder::query()->where('document_type', 'order')->whereIn('status', ['confirmed', 'processing', 'partial'])->count(),
            ],
        ]);
    }
}
