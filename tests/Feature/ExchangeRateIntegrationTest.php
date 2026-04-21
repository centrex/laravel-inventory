<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Illuminate\Support\Facades\{DB, Schema};

beforeEach(function (): void {
    Schema::create('oer_exchange_rates', function ($table): void {
        $table->id();
        $table->string('base', 3)->default('USD');
        $table->string('currency', 3);
        $table->decimal('rate', 18, 8);
        $table->timestamp('fetched_at');
        $table->timestamps();
        $table->unique(['base', 'currency']);
    });
});

it('stores manual inventory exchange rates in the open exchange rates table', function (): void {
    $inventory = app(Inventory::class);

    $stored = $inventory->setExchangeRate('USD', 110.25, '2026-04-10');

    expect($stored->base)->toBe('BDT')
        ->and($stored->currency)->toBe('USD')
        ->and((float) $stored->rate)->toBe(110.25)
        ->and((float) $inventory->getExchangeRate('USD', '2026-04-10'))->toBe(110.25)
        ->and((float) DB::table('oer_exchange_rates')->where('base', 'BDT')->where('currency', 'USD')->value('rate'))->toBe(110.25);
});

it('derives inventory exchange rates from a shared open exchange rates base', function (): void {
    $inventory = app(Inventory::class);

    DB::table('oer_exchange_rates')->insert([
        [
            'base'       => 'USD',
            'currency'   => 'GBP',
            'rate'       => 0.8,
            'fetched_at' => '2026-04-10 23:59:59',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'base'       => 'USD',
            'currency'   => 'BDT',
            'rate'       => 120,
            'fetched_at' => '2026-04-10 23:59:59',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect($inventory->getExchangeRate('USD', '2026-04-10'))->toBe(120.0)
        ->and($inventory->getExchangeRate('GBP', '2026-04-10'))->toBe(150.0);
});
