<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of the inventory dashboard's Forecast tab — Inventory::salesForecast() is the
 * heaviest computation in the codebase (loads every matching sale order with items, every
 * WarehouseProduct row for referenced products, every PurchaseOrderItem, plus several more
 * full scans for customer/geographic forecasting), and the dashboard blade only mounts this
 * component once the Forecast tab is actually opened (see dashboard.blade.php's
 * `<template x-if="activeTab === 'forecast'">` wrapper) — `lazy` alone isn't enough here
 * since the tab content is otherwise still present (just hidden) in the initial DOM.
 */
class InventoryForecastCard extends Component
{
    use CachesData;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
        $this->cacheTtl = 600;
    }

    public function forecast(): array
    {
        return $this->rememberCache(
            $this->cacheKey('inventory', 'forecast-card'),
            fn (): array => $this->sanitizeForCache(app(Inventory::class)->salesForecast()),
        );
    }

    /**
     * salesForecast()'s 'products'/'customers'/'zones'/'areas'/'demographics' entries are
     * Collection instances (fine for its other, uncached callers) — the cache store
     * serializes this whole array with PHP's native serialize(), and a Collection
     * surviving into that blob comes back as __PHP_Incomplete_Class if its class isn't
     * autoloaded yet on the next request, so a later ->count()/iteration throws deep
     * inside the view. Flatten to plain arrays so only cache-safe data crosses that
     * boundary — every consumer here (this card's Blade view) already wraps these with
     * collect()/foreach, both of which work identically on a plain array.
     *
     * @param  array<string, mixed>  $forecast
     * @return array<string, mixed>
     */
    private function sanitizeForCache(array $forecast): array
    {
        foreach (['products', 'customers', 'zones', 'areas', 'demographics'] as $key) {
            if ($forecast[$key] instanceof Collection) {
                $forecast[$key] = $forecast[$key]->all();
            }
        }

        return $forecast;
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading forecast" class="space-y-6 animate-pulse">
                <div class="stats shadow w-full">
                    @for ($i = 0; $i < 3; $i++)
                        <div class="stat"><div class="h-3 w-20 rounded bg-base-300 mb-2"></div><div class="h-6 w-24 rounded bg-base-300"></div></div>
                    @endfor
                </div>
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <div class="h-48 rounded-2xl border border-base-200 bg-base-100"></div>
                    <div class="h-48 rounded-2xl border border-base-200 bg-base-100 xl:col-span-2"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-forecast-card', [
            'forecast' => $this->forecast(),
        ]);
    }
}
