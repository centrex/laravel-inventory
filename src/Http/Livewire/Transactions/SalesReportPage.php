<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\{Customer, Product};
use Centrex\Inventory\Support\SalesReportExcelExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Gate, Route};
use Livewire\Attributes\{Layout, Url};
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin shell: page header + date/customer/product filters + tab bar. The actual report
 * content lives in InventorySalesStatisticsCard / InventoryRecentSaleOrdersCard /
 * InventorySoldProductsCard, each lazy-loaded per tab so none of the three is computed as
 * part of this page's own render.
 *
 * Each card is keyed by wire:key="...-{{ $startDate }}-{{ $endDate }}-{{ $customerId }}-{{ $productId }}"
 * in the Blade view — changing any filter changes the key, which makes Livewire tear down
 * and re-mount (and re-fetch, via `lazy`) each card instead of silently keeping stale data.
 */
#[Layout('layouts.app')]
class SalesReportPage extends Component
{
    public string $dateRange = 'this_month';

    public string $startDate = '';

    public string $endDate = '';

    #[Url(as: 'customer', except: null)]
    public ?int $customerId = null;

    #[Url(as: 'product', except: null)]
    public ?int $productId = null;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
        $this->updateDateRange();
    }

    public function updatedDateRange(): void
    {
        $this->updateDateRange();
    }

    /** 'this_month'/'this_quarter' are month-/quarter-to-date (through today), matching this page's pre-existing default range; 'last_month'/'last_quarter' are the full prior calendar month/quarter. */
    private function updateDateRange(): void
    {
        match ($this->dateRange) {
            'this_month'   => [$this->startDate = now()->startOfMonth()->toDateString(), $this->endDate = now()->toDateString()],
            'last_month'   => [$this->startDate = now()->subMonthNoOverflow()->startOfMonth()->toDateString(), $this->endDate = now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'this_quarter' => [$this->startDate = now()->startOfQuarter()->toDateString(), $this->endDate = now()->toDateString()],
            'last_quarter' => [$this->startDate = now()->subQuarterNoOverflow()->startOfQuarter()->toDateString(), $this->endDate = now()->subQuarterNoOverflow()->endOfQuarter()->toDateString()],
            default        => null,
        };
    }

    public function exportExcel(): StreamedResponse
    {
        return SalesReportExcelExporter::download(
            $this->startDate,
            $this->endDate,
            $this->customerId,
            $this->productId,
            'sales-report-' . now()->format('Ymd-His') . '.xlsx',
        );
    }

    public function render(): View
    {
        $selectedCustomer = $this->customerId ? Customer::query()->find($this->customerId) : null;
        $selectedProduct = $this->productId ? Product::query()->find($this->productId) : null;

        return view('inventory::livewire.transactions.sales-report', [
            'customerLedgerUrl'       => $this->customerLedgerUrl($selectedCustomer),
            'selectedCustomerOptions' => $selectedCustomer
                ? [
                    $selectedCustomer->id => [
                        'label'    => (string) ($selectedCustomer->organization_name ?: $selectedCustomer->name),
                        'sublabel' => filled($selectedCustomer->phone) ? (string) $selectedCustomer->phone : null,
                    ],
                ]
                : [],
            'selectedProductOptions' => $selectedProduct
                ? [
                    $selectedProduct->id => [
                        'label'    => (string) $selectedProduct->name,
                        'sublabel' => filled($selectedProduct->sku) ? (string) $selectedProduct->sku : null,
                    ],
                ]
                : [],
        ]);
    }

    /** Link to the customer's accounting ledger, when laravel-accounting is installed and the two records are linked. */
    private function customerLedgerUrl(?Customer $customer): ?string
    {
        if (!$customer || !$customer->accounting_customer_id || !class_exists(\Centrex\Accounting\Models\Customer::class) || !Route::has('accounting.customers.ledger')) {
            return null;
        }

        return route('accounting.customers.ledger', ['customer' => $customer->accounting_customer_id]);
    }
}
