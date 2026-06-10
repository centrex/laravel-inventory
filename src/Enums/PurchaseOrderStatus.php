<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

/**
 * Lifecycle states for a purchase order (or requisition).
 *
 * Allowed transitions (enforced by canTransitionTo() and assertTransition() in the service):
 *
 *   DRAFT → SUBMITTED | CANCELLED
 *   SUBMITTED → CONFIRMED | CANCELLED
 *   CONFIRMED → PARTIAL | RECEIVED | CANCELLED
 *   PARTIAL → RECEIVED | COMPLETED | CANCELLED
 *
 * PARTIAL is set automatically when a GRN is posted but some PO lines are still open.
 * RECEIVED is set when all lines are fully received; COMPLETED marks the PO as closed.
 */
enum PurchaseOrderStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case CONFIRMED = 'confirmed';
    case PARTIAL = 'partial';
    case RECEIVED = 'received';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT     => 'Draft',
            self::SUBMITTED => 'Submitted',
            self::CONFIRMED => 'Confirmed',
            self::PARTIAL   => 'Partially Received',
            self::RECEIVED  => 'Received',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::DRAFT     => in_array($next, [self::SUBMITTED, self::CANCELLED]),
            self::SUBMITTED => in_array($next, [self::CONFIRMED, self::CANCELLED]),
            self::CONFIRMED => in_array($next, [self::PARTIAL, self::RECEIVED, self::CANCELLED]),
            self::PARTIAL   => in_array($next, [self::RECEIVED, self::COMPLETED, self::CANCELLED]),
            default         => false,
        };
    }
}
