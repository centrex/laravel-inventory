<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

/**
 * Lifecycle states for a sale order (or quotation).
 *
 * Allowed transitions (enforced by canTransitionTo() and assertTransition() in the service):
 *
 *   DRAFT → CONFIRMED | CANCELLED
 *   CONFIRMED → PROCESSING | CANCELLED
 *   PROCESSING → PARTIAL | FULFILLED | CANCELLED
 *   PARTIAL → FULFILLED | CANCELLED
 *   FULFILLED → COMPLETED | RETURNED
 *   COMPLETED → RETURNED
 *
 * SHIPPED is a parallel state set by the shipment workflow; it does not gate fulfilment.
 */
enum SaleOrderStatus: string
{
    case DRAFT = 'draft';
    case CONFIRMED = 'confirmed';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case PARTIAL = 'partial';
    case FULFILLED = 'fulfilled';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT      => 'Draft',
            self::CONFIRMED  => 'Confirmed',
            self::PROCESSING => 'Processing',
            self::SHIPPED    => 'Shipped',
            self::PARTIAL    => 'Partially Fulfilled',
            self::FULFILLED  => 'Fulfilled',
            self::COMPLETED  => 'Completed',
            self::CANCELLED  => 'Cancelled',
            self::RETURNED   => 'Returned',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::DRAFT      => in_array($next, [self::CONFIRMED, self::CANCELLED]),
            self::CONFIRMED  => in_array($next, [self::PROCESSING, self::CANCELLED]),
            self::PROCESSING => in_array($next, [self::PARTIAL, self::FULFILLED, self::CANCELLED]),
            self::PARTIAL    => in_array($next, [self::FULFILLED, self::CANCELLED]),
            self::FULFILLED  => in_array($next, [self::COMPLETED, self::RETURNED]),
            self::COMPLETED  => in_array($next, [self::RETURNED]),
            default          => false,
        };
    }
}
