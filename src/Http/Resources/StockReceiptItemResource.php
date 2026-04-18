<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockReceiptItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'stock_receipt_id'       => $this->stock_receipt_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'product_id'             => $this->product_id,
            'qty_received'           => $this->qty_received,
            'unit_cost_local'        => $this->unit_cost_local,
            'unit_cost_amount'       => $this->unit_cost_amount,
            'exchange_rate'          => $this->exchange_rate,
            'wac_before_amount'      => $this->wac_before_amount,
            'wac_after_amount'       => $this->wac_after_amount,
            'notes'                  => $this->notes,
            'product'                => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
