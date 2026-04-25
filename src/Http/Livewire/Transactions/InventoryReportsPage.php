<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{PurchaseOrder, SaleOrder};
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class InventoryReportsPage extends Component
{
    public string $startDate = '';

    public string $endDate = '';

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
        $this->endDate = now()->toDateString();
        $this->startDate = now()->subDays(29)->toDateString();
    }

    public function render(): View
    {
        $inventory = app(Inventory::class);
        $purchaseQuery = PurchaseOrder::query()
            ->with(['supplier', 'warehouse'])
            ->where('document_type', 'order')
            ->when($this->startDate !== '', fn ($query) => $query->whereDate('ordered_at', '>=', $this->startDate))
            ->when($this->endDate !== '', fn ($query) => $query->whereDate('ordered_at', '<=', $this->endDate))
            ->latest('ordered_at')
            ->latest('id')
            ->limit(25);

        CommercialTeamAccess::applyPurchaseScope($purchaseQuery);

        $purchaseOrders = $purchaseQuery->get();

        $salesQuery = SaleOrder::query()
            ->with(['customer', 'warehouse'])
            ->where('document_type', 'order')
            ->when($this->startDate !== '', fn ($query) => $query->whereDate('ordered_at', '>=', $this->startDate))
            ->when($this->endDate !== '', fn ($query) => $query->whereDate('ordered_at', '<=', $this->endDate))
            ->latest('ordered_at')
            ->latest('id')
            ->limit(25);

        CommercialTeamAccess::applySalesScope($salesQuery);

        $saleOrders = $salesQuery->get();

        $salesMetrics = $this->buildSalesMetrics($saleOrders);
        $purchaseMetrics = $this->buildPurchaseMetrics($purchaseOrders);
        $paymentMetrics = $this->buildPaymentMetrics($this->startDate, $this->endDate);
        $forecast = $inventory->salesForecast(
            lookbackDays: $this->forecastLookbackDays(),
            forecastDays: $this->forecastHorizonDays(),
        );

        return view('inventory::livewire.transactions.inventory-reports', [
            'purchaseOrders'  => $purchaseOrders,
            'saleOrders'      => $saleOrders,
            'salesMetrics'    => $salesMetrics,
            'purchaseMetrics' => $purchaseMetrics,
            'paymentMetrics'  => $paymentMetrics,
            'forecast'        => $forecast,
        ]);
    }

    private function buildSalesMetrics(Collection $saleOrders): array
    {
        $invoiceSummary = $this->invoiceSummary($this->startDate, $this->endDate);

        return [
            'count'           => $saleOrders->count(),
            'gross_subtotal'  => round((float) $saleOrders->sum('subtotal_local'), 2),
            'discount'        => round((float) $saleOrders->sum('discount_local'), 2),
            'tax'             => round((float) $saleOrders->sum('tax_local'), 2),
            'net_total'       => round((float) $saleOrders->sum('total_local'), 2),
            'fulfilled_total' => round((float) $saleOrders
                ->filter(fn (SaleOrder $order) => in_array($order->status?->value, ['fulfilled', 'partial'], true))
                ->sum('total_local'), 2),
            'invoice_paid'  => $invoiceSummary['paid'],
            'invoice_due'   => $invoiceSummary['due'],
            'status_counts' => $saleOrders->groupBy(fn (SaleOrder $order) => $order->status?->value ?? 'unknown')
                ->map(fn (Collection $group) => $group->count())
                ->all(),
        ];
    }

    private function buildPurchaseMetrics(Collection $purchaseOrders): array
    {
        $billSummary = $this->billSummary($this->startDate, $this->endDate);

        return [
            'count'          => $purchaseOrders->count(),
            'gross_subtotal' => round((float) $purchaseOrders->sum('subtotal_local'), 2),
            'tax'            => round((float) $purchaseOrders->sum('tax_local'), 2),
            'shipping'       => round((float) $purchaseOrders->sum('shipping_local'), 2),
            'other_charges'  => round((float) $purchaseOrders->sum('other_charges_amount'), 2),
            'net_total'      => round((float) $purchaseOrders->sum('total_local'), 2),
            'received_total' => round((float) $purchaseOrders->filter(fn (PurchaseOrder $order) => in_array($order->status?->value, ['received', 'partial'], true))->sum('total_local'), 2),
            'bill_paid'      => $billSummary['paid'],
            'bill_due'       => $billSummary['due'],
            'status_counts'  => $purchaseOrders->groupBy(fn (PurchaseOrder $order) => $order->status?->value ?? 'unknown')
                ->map(fn (Collection $group) => $group->count())
                ->all(),
        ];
    }

    private function buildPaymentMetrics(string $startDate, string $endDate): array
    {
        $paymentClass = \Centrex\Accounting\Models\Payment::class;
        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;
        $billClass = \Centrex\Accounting\Models\Bill::class;

        if (!class_exists($paymentClass) || !class_exists($invoiceClass) || !class_exists($billClass)) {
            return [
                'available'         => false,
                'count'             => 0,
                'collections'       => 0.0,
                'supplier_payments' => 0.0,
                'recent'            => collect(),
            ];
        }

        $payments = $paymentClass::query()
            ->with('payable')
            ->when($startDate !== '', fn ($query) => $query->whereDate('payment_date', '>=', $startDate))
            ->when($endDate !== '', fn ($query) => $query->whereDate('payment_date', '<=', $endDate))
            ->latest('payment_date')
            ->latest('id')
            ->limit(25)
            ->get();

        return [
            'available'         => true,
            'count'             => $payments->count(),
            'collections'       => round((float) $payments->where('payable_type', $invoiceClass)->sum('amount'), 2),
            'supplier_payments' => round((float) $payments->where('payable_type', $billClass)->sum('amount'), 2),
            'recent'            => $payments,
        ];
    }

    private function invoiceSummary(string $startDate, string $endDate): array
    {
        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;

        if (!class_exists($invoiceClass)) {
            return ['paid' => 0.0, 'due' => 0.0];
        }

        $invoices = $invoiceClass::query()
            ->whereNotNull('inventory_sale_order_id')
            ->when($startDate !== '', fn ($query) => $query->whereDate('invoice_date', '>=', $startDate))
            ->when($endDate !== '', fn ($query) => $query->whereDate('invoice_date', '<=', $endDate))
            ->get();

        return [
            'paid' => round((float) $invoices->sum('base_paid_amount'), 2),
            'due'  => round((float) $invoices->sum('base_balance'), 2),
        ];
    }

    private function billSummary(string $startDate, string $endDate): array
    {
        $billClass = \Centrex\Accounting\Models\Bill::class;

        if (!class_exists($billClass)) {
            return ['paid' => 0.0, 'due' => 0.0];
        }

        $bills = $billClass::query()
            ->whereNotNull('inventory_purchase_order_id')
            ->when($startDate !== '', fn ($query) => $query->whereDate('bill_date', '>=', $startDate))
            ->when($endDate !== '', fn ($query) => $query->whereDate('bill_date', '<=', $endDate))
            ->get();

        return [
            'paid' => round((float) $bills->sum('base_paid_amount'), 2),
            'due'  => round((float) $bills->sum('base_balance'), 2),
        ];
    }

    private function forecastLookbackDays(): int
    {
        if ($this->startDate === '' || $this->endDate === '') {
            return 90;
        }

        $days = now()->parse($this->startDate)->diffInDays(now()->parse($this->endDate)) + 1;

        return max(30, $days);
    }

    private function forecastHorizonDays(): int
    {
        return 90;
    }
}
