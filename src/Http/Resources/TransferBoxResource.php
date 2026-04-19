<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferBoxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'transfer_id'        => $this->transfer_id,
            'box_code'           => $this->box_code,
            'measured_weight_kg' => $this->measured_weight_kg,
            'notes'              => $this->notes,
            'items'              => TransferBoxItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
