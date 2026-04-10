<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum MovementType: string
{
    case PURCHASE_RECEIPT    = 'purchase_receipt';
    case SALE_FULFILLMENT    = 'sale_fulfillment';
    case TRANSFER_OUT        = 'transfer_out';
    case TRANSFER_IN         = 'transfer_in';
    case ADJUSTMENT_IN       = 'adjustment_in';
    case ADJUSTMENT_OUT      = 'adjustment_out';
    case OPENING_STOCK       = 'opening_stock';
    case RETURN_TO_SUPPLIER  = 'return_to_supplier';
    case CUSTOMER_RETURN     = 'customer_return';

    public function direction(): string
    {
        return match ($this) {
            self::PURCHASE_RECEIPT,
            self::TRANSFER_IN,
            self::ADJUSTMENT_IN,
            self::OPENING_STOCK,
            self::CUSTOMER_RETURN  => 'in',

            self::SALE_FULFILLMENT,
            self::TRANSFER_OUT,
            self::ADJUSTMENT_OUT,
            self::RETURN_TO_SUPPLIER => 'out',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE_RECEIPT   => 'Purchase Receipt',
            self::SALE_FULFILLMENT   => 'Sale Fulfillment',
            self::TRANSFER_OUT       => 'Transfer Out',
            self::TRANSFER_IN        => 'Transfer In',
            self::ADJUSTMENT_IN      => 'Adjustment (In)',
            self::ADJUSTMENT_OUT     => 'Adjustment (Out)',
            self::OPENING_STOCK      => 'Opening Stock',
            self::RETURN_TO_SUPPLIER => 'Return to Supplier',
            self::CUSTOMER_RETURN    => 'Customer Return',
        };
    }
}
