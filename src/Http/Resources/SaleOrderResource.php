<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                            => $this->id,
            'so_number'                     => $this->so_number,
            'warehouse_id'                  => $this->warehouse_id,
            'customer_id'                   => $this->customer_id,
            'coupon_id'                     => $this->coupon_id,
            'price_tier_code'               => $this->price_tier_code,
            'coupon_code'                   => $this->coupon_code,
            'coupon_name'                   => $this->coupon_name,
            'coupon_discount_type'          => $this->coupon_discount_type,
            'coupon_discount_value'         => $this->coupon_discount_value,
            'currency'                      => $this->currency,
            'exchange_rate'                 => $this->exchange_rate,
            'subtotal_local'                => $this->subtotal_local,
            'subtotal_amount'               => $this->subtotal_amount,
            'tax_local'                     => $this->tax_local,
            'tax_amount'                    => $this->tax_amount,
            'discount_local'                => $this->discount_local,
            'discount_amount'               => $this->discount_amount,
            'coupon_discount_local'         => $this->coupon_discount_local,
            'coupon_discount_amount'        => $this->coupon_discount_amount,
            'total_local'                   => $this->total_local,
            'total_amount'                  => $this->total_amount,
            'credit_limit_amount'           => $this->credit_limit_amount,
            'credit_exposure_before_amount' => $this->credit_exposure_before_amount,
            'credit_exposure_after_amount'  => $this->credit_exposure_after_amount,
            'credit_override_required'      => $this->credit_override_required,
            'credit_override_approved_by'   => $this->credit_override_approved_by,
            'credit_override_approved_at'   => $this->credit_override_approved_at?->toIso8601String(),
            'credit_override_notes'         => $this->credit_override_notes,
            'cogs_amount'                   => $this->cogs_amount,
            'status'                        => $this->status,
            'ordered_at'                    => $this->ordered_at?->toIso8601String(),
            'notes'                         => $this->notes,
            'created_by'                    => $this->created_by,
            'accounting_invoice_id'         => $this->accounting_invoice_id,
            'created_at'                    => $this->created_at?->toIso8601String(),
            'updated_at'                    => $this->updated_at?->toIso8601String(),
            'items'                         => SaleOrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
