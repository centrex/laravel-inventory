<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Illuminate\Support\Facades\DB;

it('stores manual inventory exchange rates in the open exchange rates table', function (): void {
    $inventory = app(Inventory::class);

    $stored = $inventory->setExchangeRate('USD', 110.25, '2026-04-10');

    expect($stored->base)->toBe('BDT')
        ->and($stored->date->toDateString())->toBe('2026-04-10')
        ->and($stored->rateFor('USD'))->toBe(110.25)
        ->and((float) $inventory->getExchangeRate('USD', '2026-04-10'))->toBe(110.25)
        ->and((float) json_decode(DB::table('oer_exchange_rates')->where('base', 'BDT')->whereDate('date', '2026-04-10')->value('rates'), true)['USD'])->toBe(110.25);
});

it('derives inventory exchange rates from a shared open exchange rates base', function (): void {
    $inventory = app(Inventory::class);

    DB::table('oer_exchange_rates')->insert([
        'date'       => '2026-04-10',
        'base'       => 'USD',
        'rates'      => json_encode(['GBP' => 0.8, 'BDT' => 120]),
        'fetched_at' => '2026-04-10 23:59:59',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($inventory->getExchangeRate('USD', '2026-04-10'))->toBe(120.0)
        ->and($inventory->getExchangeRate('GBP', '2026-04-10'))->toBe(150.0);
});
