<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum StockReceiptStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case VOID = 'void';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT  => 'Draft',
            self::POSTED => 'Posted',
            self::VOID   => 'Void',
        };
    }
}
