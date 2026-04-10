<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum PriceTierCode: string
{
    case BASE = 'base';
    case WHOLESALE = 'wholesale';
    case RETAIL = 'retail';
    case DROPSHIPPING = 'dropshipping';
    case FCOM = 'fcom';

    public function label(): string
    {
        return match ($this) {
            self::BASE         => 'Base',
            self::WHOLESALE    => 'Wholesale',
            self::RETAIL       => 'Retail',
            self::DROPSHIPPING => 'Dropshipping',
            self::FCOM         => 'F-Commerce',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::BASE         => 1,
            self::WHOLESALE    => 2,
            self::RETAIL       => 3,
            self::DROPSHIPPING => 4,
            self::FCOM         => 5,
        };
    }
}
