<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'purchase_order_id'  => $this->purchase_order_id,
            'product_id'         => $this->product_id,
            'qty_ordered'        => $this->qty_ordered,
            'qty_received'       => $this->qty_received,
            'qty_pending'        => $this->qtyPending(),
            'unit_price_local'   => $this->unit_price_local,
            'unit_price_amount'  => $this->unit_price_amount,
            'line_total_local'   => $this->line_total_local,
            'line_total_amount'  => $this->line_total_amount,
            'notes'              => $this->notes,
            'product'            => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
