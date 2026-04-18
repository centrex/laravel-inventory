<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'sku'          => $this->sku,
            'name'         => $this->name,
            'unit'         => $this->unit,
            'weight_kg'    => $this->weight_kg,
            'barcode'      => $this->barcode,
            'is_active'    => $this->is_active,
            'is_stockable' => $this->is_stockable,
        ];
    }
}
