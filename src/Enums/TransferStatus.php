<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum TransferStatus: string
{
    case PENDING = 'pending';
    case DISPATCHED = 'dispatched';
    case DELIVERED = 'delivered';
    case RETURNED = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::PENDING    => 'Pending',
            self::DISPATCHED => 'Dispatched',
            self::DELIVERED  => 'Delivered',
            self::RETURNED   => 'Returned',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::PENDING    => in_array($next, [self::DISPATCHED]),
            self::DISPATCHED => in_array($next, [self::DELIVERED, self::RETURNED]),
            default          => false,
        };
    }
}
