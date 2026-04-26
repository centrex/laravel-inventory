<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

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
