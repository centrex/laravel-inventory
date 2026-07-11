<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Centrex\LaravelOpenExchangeRates\Client as OpenExchangeRatesClient;
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

it('fetches a missing exchange rate live and persists it for future lookups', function (): void {
    $client = Mockery::mock(OpenExchangeRatesClient::class);
    $client->shouldReceive('latest')
        ->once()
        ->with('EUR,BDT,USD')
        ->andReturn([
            'base'  => 'USD',
            'rates' => ['EUR' => 0.92, 'BDT' => 110.5],
        ]);

    app()->instance(OpenExchangeRatesClient::class, $client);

    $inventory = app(Inventory::class);

    expect($inventory->getExchangeRate('EUR'))->toBe(round(110.5 / 0.92, 8))
        ->and(DB::table('oer_exchange_rates')->where('base', 'USD')->exists())->toBeTrue();

    // Second call must not hit the API again — it now resolves from the persisted row.
    expect($inventory->getExchangeRate('EUR'))->toBe(round(110.5 / 0.92, 8));
});

it('does not fetch live rates when exchange_rate_live_fetch is disabled', function (): void {
    config()->set('inventory.exchange_rate_live_fetch', false);

    $client = Mockery::mock(OpenExchangeRatesClient::class);
    $client->shouldNotReceive('latest');
    $client->shouldNotReceive('historical');

    app()->instance(OpenExchangeRatesClient::class, $client);

    $inventory = app(Inventory::class);

    expect(fn () => $inventory->getExchangeRate('EUR'))->toThrow(RuntimeException::class);
});
