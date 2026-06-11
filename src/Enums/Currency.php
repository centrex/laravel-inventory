<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Enums;

enum Currency: string
{
    case AED = 'AED';
    case AUD = 'AUD';
    case BDT = 'BDT';
    case BRL = 'BRL';
    case CAD = 'CAD';
    case CHF = 'CHF';
    case CNY = 'CNY';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case HKD = 'HKD';
    case IDR = 'IDR';
    case INR = 'INR';
    case JPY = 'JPY';
    case KRW = 'KRW';
    case MYR = 'MYR';
    case NGN = 'NGN';
    case PKR = 'PKR';
    case SAR = 'SAR';
    case SGD = 'SGD';
    case THB = 'THB';
    case TRY = 'TRY';
    case USD = 'USD';
    case VND = 'VND';
    case ZAR = 'ZAR';

    public function label(): string
    {
        return match ($this) {
            self::AED => 'UAE Dirham',
            self::AUD => 'Australian Dollar',
            self::BDT => 'Bangladeshi Taka',
            self::BRL => 'Brazilian Real',
            self::CAD => 'Canadian Dollar',
            self::CHF => 'Swiss Franc',
            self::CNY => 'Chinese Yuan',
            self::EUR => 'Euro',
            self::GBP => 'British Pound',
            self::HKD => 'Hong Kong Dollar',
            self::IDR => 'Indonesian Rupiah',
            self::INR => 'Indian Rupee',
            self::JPY => 'Japanese Yen',
            self::KRW => 'South Korean Won',
            self::MYR => 'Malaysian Ringgit',
            self::NGN => 'Nigerian Naira',
            self::PKR => 'Pakistani Rupee',
            self::SAR => 'Saudi Riyal',
            self::SGD => 'Singapore Dollar',
            self::THB => 'Thai Baht',
            self::TRY => 'Turkish Lira',
            self::USD => 'US Dollar',
            self::VND => 'Vietnamese Dong',
            self::ZAR => 'South African Rand',
        };
    }

    public function selectOption(): string
    {
        return $this->value . ' – ' . $this->label();
    }

    /** @return array<string, string> keyed by currency code */
    public static function options(): array
    {
        return array_column(
            array_map(
                static fn (self $c): array => [$c->value, $c->selectOption()],
                self::cases(),
            ),
            1,
            0,
        );
    }
}
