<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions\Concerns;

use Centrex\Inventory\Inventory;
use Illuminate\Support\Collection;

/**
 * Shared by every lazy forecast card (InventoryForecastCard on the dashboard;
 * InventoryForecastSummaryCard, InventoryForecastProductCustomerCard, and
 * InventoryForecastGeoCard on the Forecast Report page) so Inventory::salesForecast() — the
 * heaviest computation in the codebase — is cached under one key per lookback/horizon
 * combination and only actually runs once, even though several independently-mounted cards
 * call it. Requires the using class to `use CachesData` for rememberCache()/cacheKey().
 */
trait CachesSalesForecast
{
    protected function forecastData(int $lookbackDays, int $forecastDays): array
    {
        return $this->rememberCache(
            $this->cacheKey('inventory', 'forecast-report', (string) $lookbackDays, (string) $forecastDays),
            fn (): array => $this->sanitizeForecastForCache(app(Inventory::class)->salesForecast(
                lookbackDays: $lookbackDays,
                forecastDays: $forecastDays,
            )),
        );
    }

    /**
     * salesForecast()'s 'products'/'customers'/'zones'/'areas'/'demographics' entries are
     * Collection instances (fine for its other, uncached callers) — the cache store
     * serializes this whole array with PHP's native serialize(), and a Collection surviving
     * into that blob comes back as __PHP_Incomplete_Class if its class isn't autoloaded yet
     * on the next request, so a later ->count()/iteration throws deep inside the view.
     * Flatten to plain arrays so only cache-safe data crosses that boundary — every consumer
     * (each card's Blade view) already wraps these with collect()/foreach, both of which
     * work identically on a plain array.
     *
     * @param  array<string, mixed>  $forecast
     * @return array<string, mixed>
     */
    private function sanitizeForecastForCache(array $forecast): array
    {
        foreach (['products', 'customers', 'zones', 'areas', 'demographics'] as $key) {
            if ($forecast[$key] instanceof Collection) {
                $forecast[$key] = $forecast[$key]->all();
            }
        }

        return $forecast;
    }
}
