<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum TransferStatus: string
{
    case DRAFT      = 'draft';
    case IN_TRANSIT = 'in_transit';
    case PARTIAL    = 'partial';
    case RECEIVED   = 'received';
    case CANCELLED  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT      => 'Draft',
            self::IN_TRANSIT => 'In Transit',
            self::PARTIAL    => 'Partially Received',
            self::RECEIVED   => 'Received',
            self::CANCELLED  => 'Cancelled',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::DRAFT      => in_array($next, [self::IN_TRANSIT, self::CANCELLED]),
            self::IN_TRANSIT => in_array($next, [self::PARTIAL, self::RECEIVED]),
            self::PARTIAL    => in_array($next, [self::RECEIVED]),
            default          => false,
        };
    }
}
