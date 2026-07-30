<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\{InventoryForecastCard, InventorySalesTargetCard};
use Illuminate\Support\Facades\{DB, Gate};

beforeEach(function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);
    config()->set('cache.default', 'array');
});

it('InventoryForecastCard computes a forecast and caches the result', function (): void {
    $component = new InventoryForecastCard;
    $component->mount();

    $forecast = $component->forecast();

    expect($forecast)->toHaveKeys(['window', 'summary', 'products', 'customers', 'timeline']);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->forecast();

    expect($queryCount)->toBe(0);
});

it('InventorySalesTargetCard reads inputs from the query string and caches the result', function (): void {
    request()->merge(['target_lookback_days' => 60, 'target_days' => 14]);

    $component = new InventorySalesTargetCard;
    $component->mount();

    expect($component->lookbackDays)->toBe(60)
        ->and($component->targetDays)->toBe(14);

    $target = $component->salesTarget();

    expect($target)->toHaveKeys(['window', 'target', 'history', 'inputs', 'availability']);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->salesTarget();

    expect($queryCount)->toBe(0);
});

it('the dashboard blade only mounts the forecast/target cards behind an Alpine x-if for their own tab', function (): void {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/dashboard.blade.php');

    expect($blade)
        ->toContain('<template x-if="activeTab === \'forecast\'">')
        ->toContain('<livewire:inventory-forecast-card lazy />')
        ->toContain('<template x-if="activeTab === \'target\'">')
        ->toContain('<livewire:inventory-sales-target-card lazy />');
});
