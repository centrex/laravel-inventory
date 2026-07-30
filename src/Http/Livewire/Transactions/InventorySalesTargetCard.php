<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of the inventory dashboard's Sales Target tab — SalesTargetCalculator re-scans
 * a lookback window of orders/expenses/payroll, same lazy + tab-gated treatment as
 * InventoryForecastCard (see that class's docblock). Inputs come straight from the request
 * query string (the "Sales Target Inputs" form does a full GET reload on submit), not from
 * Livewire-bound props, so there's no #[Reactive] here.
 */
class InventorySalesTargetCard extends Component
{
    use CachesData;

    public int $lookbackDays;

    public int $targetDays;

    public ?float $expectedGrossMarginPct;

    public float $desiredNetMarginPct;

    public float $growthPct;

    public ?float $expenseAllocationPct;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');

        $this->lookbackDays = request()->integer('target_lookback_days', 90);
        $this->targetDays = request()->integer('target_days', 30);
        $this->expectedGrossMarginPct = request()->filled('target_gross_margin_pct') ? (float) request()->input('target_gross_margin_pct') : null;
        $this->desiredNetMarginPct = request()->filled('target_net_margin_pct') ? (float) request()->input('target_net_margin_pct') : 10.0;
        $this->growthPct = request()->filled('target_growth_pct') ? (float) request()->input('target_growth_pct') : 0.0;
        $this->expenseAllocationPct = request()->filled('target_expense_allocation_pct') ? (float) request()->input('target_expense_allocation_pct') : null;

        $this->cacheTtl = 600;
    }

    public function salesTarget(): array
    {
        return $this->rememberCache(
            $this->cacheKey(
                'inventory',
                'sales-target-card',
                (string) $this->lookbackDays,
                (string) $this->targetDays,
                (string) $this->expectedGrossMarginPct,
                (string) $this->desiredNetMarginPct,
                (string) $this->growthPct,
                (string) $this->expenseAllocationPct,
            ),
            fn (): array => app(Inventory::class)->salesTarget(
                lookbackDays: $this->lookbackDays,
                targetDays: $this->targetDays,
                expectedGrossMarginPct: $this->expectedGrossMarginPct,
                desiredNetMarginPct: $this->desiredNetMarginPct,
                growthPct: $this->growthPct,
                expenseAllocationPct: $this->expenseAllocationPct,
            ),
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading sales target" class="space-y-6 animate-pulse">
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
        return view('inventory::livewire.transactions.inventory-sales-target-card', [
            'salesTarget' => $this->salesTarget(),
        ]);
    }
}
