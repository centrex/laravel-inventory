<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'transfer_id'                => $this->transfer_id,
            'product_id'                 => $this->product_id,
            'qty_sent'                   => $this->qty_sent,
            'qty_received'               => $this->qty_received,
            'unit_cost_source_amount'    => $this->unit_cost_source_amount,
            'weight_kg_total'            => $this->weight_kg_total,
            'shipping_allocated_amount'  => $this->shipping_allocated_amount,
            'unit_landed_cost_amount'    => $this->unit_landed_cost_amount,
            'wac_source_before_amount'   => $this->wac_source_before_amount,
            'wac_dest_before_amount'     => $this->wac_dest_before_amount,
            'wac_dest_after_amount'      => $this->wac_dest_after_amount,
            'notes'                      => $this->notes,
            'product'                    => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
