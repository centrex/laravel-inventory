<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum AdjustmentReason: string
{
    case CYCLE_COUNT = 'cycle_count';
    case WRITE_OFF = 'write_off';
    case DAMAGE = 'damage';
    case THEFT = 'theft';
    case EXPIRY = 'expiry';
    case FOUND_STOCK = 'found_stock';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CYCLE_COUNT => 'Cycle Count',
            self::WRITE_OFF   => 'Write Off',
            self::DAMAGE      => 'Damage',
            self::THEFT       => 'Theft',
            self::EXPIRY      => 'Expiry',
            self::FOUND_STOCK => 'Found Stock',
            self::OTHER       => 'Other',
        };
    }
}
