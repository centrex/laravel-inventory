<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

/**
 * Maps the various status/enum values used across inventory documents
 * (purchase orders, sale orders, transfers, shipments, returns, pick lists)
 * to a consistent tallui badge color.
 */
class StatusBadge
{
    private const TYPE_MAP = [
        'draft'       => 'neutral',
        'pending'     => 'neutral',
        'requisition' => 'neutral',
        'submitted'   => 'info',
        'confirmed'   => 'info',
        'processing'  => 'info',
        'picking'     => 'info',
        'in_transit'  => 'info',
        'dispatched'  => 'info',
        'shipped'     => 'primary',
        'partial'     => 'warning',
        'received'    => 'success',
        'fulfilled'   => 'success',
        'completed'   => 'success',
        'delivered'   => 'success',
        'posted'      => 'success',
        'picked'      => 'success',
        'settled'     => 'success',
        'active'      => 'success',
        'cancelled'   => 'error',
        'void'        => 'error',
        'returned'    => 'error',
    ];

    public static function type(mixed $status): string
    {
        $value = $status instanceof \BackedEnum ? $status->value : (string) $status;

        return self::TYPE_MAP[strtolower($value)] ?? 'neutral';
    }
}
