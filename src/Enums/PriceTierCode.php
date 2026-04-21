<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum PriceTierCode: string
{
    case BASE = 'base';
    case B2B_WHOLESALE = 'b2b_wholesale';
    case B2B_RETAIL = 'b2b_retail';
    case B2B_DROPSHIP = 'b2b_dropship';
    case B2C_RETAIL = 'b2c_retail';
    case B2C_ECOM = 'b2c_ecom';
    case B2C_POS = 'b2c_pos';

    public function label(): string
    {
        return match ($this) {
            self::BASE          => 'Base',
            self::B2B_WHOLESALE => 'B2B Wholesale',
            self::B2B_RETAIL    => 'B2B Retail',
            self::B2B_DROPSHIP  => 'B2B Dropship',
            self::B2C_RETAIL    => 'B2C Retail',
            self::B2C_ECOM      => 'B2C E-Commerce',
            self::B2C_POS       => 'B2C POS',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::BASE          => 1,
            self::B2B_WHOLESALE => 2,
            self::B2B_RETAIL    => 3,
            self::B2B_DROPSHIP  => 4,
            self::B2C_RETAIL    => 5,
            self::B2C_ECOM      => 6,
            self::B2C_POS       => 7,
        };
    }

    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $left, self $right): int => $left->sortOrder() <=> $right->sortOrder());

        return $cases;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $tier): array => [
                'code'       => $tier->value,
                'name'       => $tier->label(),
                'sort_order' => $tier->sortOrder(),
            ],
            self::ordered(),
        );
    }

    public static function values(): array
    {
        return array_map(fn (self $tier): string => $tier->value, self::cases());
    }

    public static function labelFor(?string $code): ?string
    {
        return $code ? self::tryFrom($code)?->label() : null;
    }
}
