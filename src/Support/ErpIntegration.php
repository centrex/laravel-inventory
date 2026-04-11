<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\Adjustment;
use Centrex\Inventory\Models\Customer as InventoryCustomer;
use Centrex\Inventory\Models\Product;
use Centrex\Inventory\Models\PurchaseOrder;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Models\StockReceipt;
use Centrex\Inventory\Models\Supplier as InventorySupplier;
use Illuminate\Support\Facades\DB;

class ErpIntegration
{
    public function enabled(): bool
    {
        return (bool) config('inventory.erp.accounting.enabled', true)
            && app()->bound('accounting')
            && class_exists(\Centrex\Accounting\Accounting::class)
            && class_exists(\Centrex\Accounting\Models\Account::class);
    }

    public function syncCustomer(?InventoryCustomer $customer): ?int
    {
        if (!$customer || !$this->enabled()) {
            return null;
        }

        $accountingCustomerClass = \Centrex\Accounting\Models\Customer::class;
        $accountingCustomer = $customer->accounting_customer_id
            ? $accountingCustomerClass::find($customer->accounting_customer_id)
            : $accountingCustomerClass::where('modelable_type', InventoryCustomer::class)
                ->where('modelable_id', $customer->id)
                ->first();

        $payload = [
            'code'           => $customer->code,
            'name'           => $customer->name,
            'email'          => $customer->email,
            'phone'          => $customer->phone,
            'currency'       => $customer->currency ?? config('inventory.base_currency', 'BDT'),
            'is_active'      => (bool) $customer->is_active,
            'modelable_type' => InventoryCustomer::class,
            'modelable_id'   => $customer->id,
        ];

        if ($accountingCustomer) {
            $accountingCustomer->fill($payload)->save();
        } else {
            $accountingCustomer = $accountingCustomerClass::create($payload);
        }

        if ((int) $customer->accounting_customer_id !== (int) $accountingCustomer->id) {
            $customer->forceFill(['accounting_customer_id' => $accountingCustomer->id])->saveQuietly();
        }

        return (int) $accountingCustomer->id;
    }

    public function syncSupplier(?InventorySupplier $supplier): ?int
    {
        if (!$supplier || !$this->enabled()) {
            return null;
        }

        $vendorClass = \Centrex\Accounting\Models\Vendor::class;
        $vendor = $supplier->accounting_vendor_id
            ? $vendorClass::find($supplier->accounting_vendor_id)
            : $vendorClass::where('modelable_type', InventorySupplier::class)
                ->where('modelable_id', $supplier->id)
                ->first();

        $payload = [
            'code'           => $supplier->code,
            'name'           => $supplier->name,
            'email'          => $supplier->contact_email,
            'phone'          => $supplier->contact_phone,
            'address'        => $supplier->address,
            'country'        => $supplier->country_code,
            'currency'       => $supplier->currency ?? config('inventory.base_currency', 'BDT'),
            'is_active'      => (bool) $supplier->is_active,
            'modelable_type' => InventorySupplier::class,
            'modelable_id'   => $supplier->id,
        ];

        if ($vendor) {
            $vendor->fill($payload)->save();
        } else {
            $vendor = $vendorClass::create($payload);
        }

        if ((int) $supplier->accounting_vendor_id !== (int) $vendor->id) {
            $supplier->forceFill(['accounting_vendor_id' => $vendor->id])->saveQuietly();
        }

        return (int) $vendor->id;
    }

    public function syncSaleOrderDocument(SaleOrder $saleOrder): ?int
    {
        if (!$this->enabled() || !$saleOrder->customer_id) {
            return null;
        }

        $saleOrder->loadMissing(['customer', 'items.product']);
        $customerId = $this->syncCustomer($saleOrder->customer);

        if (!$customerId) {
            return null;
        }

        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;
        $invoice = $saleOrder->accounting_invoice_id
            ? $invoiceClass::find($saleOrder->accounting_invoice_id)
            : $invoiceClass::where('inventory_sale_order_id', $saleOrder->id)->first();

        $invoiceDate = $saleOrder->ordered_at?->toDateString() ?? now()->toDateString();
        $dueDate = $saleOrder->ordered_at?->copy()->addDays(30)->toDateString() ?? now()->addDays(30)->toDateString();
        $status = $invoice?->status?->value ?? $invoice?->status ?? 'draft';
        $lockedStatuses = ['issued', 'settled', 'partially_settled', 'overdue'];

        $invoiceData = [
            'customer_id'              => $customerId,
            'invoice_date'             => $invoiceDate,
            'due_date'                 => $dueDate,
            'subtotal'                 => round((float) $saleOrder->subtotal_amount, 2),
            'tax_amount'               => round((float) $saleOrder->tax_amount, 2),
            'discount_amount'          => round((float) $saleOrder->discount_amount, 2),
            'total'                    => round((float) $saleOrder->total_amount, 2),
            'currency'                 => $saleOrder->currency,
            'exchange_rate'            => (float) $saleOrder->exchange_rate,
            'status'                   => in_array((string) $status, $lockedStatuses, true) ? $status : 'draft',
            'notes'                    => $saleOrder->notes,
            'inventory_sale_order_id'  => $saleOrder->id,
        ];

        if ($invoice && in_array((string) $status, $lockedStatuses, true)) {
            if ((int) $saleOrder->accounting_invoice_id !== (int) $invoice->id) {
                $saleOrder->forceFill(['accounting_invoice_id' => $invoice->id])->saveQuietly();
            }

            return (int) $invoice->id;
        }

        if ($invoice) {
            $invoice->fill($invoiceData);
            $invoice->save();
        } else {
            $invoice = $invoiceClass::create($invoiceData);
        }

        DB::connection($invoice->getConnectionName())->transaction(function () use ($invoice, $saleOrder): void {
            $invoice->items()->delete();

            foreach ($saleOrder->items as $item) {
                $invoice->items()->create([
                    'description'   => $this->itemDescription($item->product, (float) $item->qty_ordered),
                    'itemable_type' => Product::class,
                    'itemable_id'   => $item->product_id,
                    'quantity'      => 1,
                    'unit_price'    => round((float) $item->line_total_amount, 2),
                    'tax_rate'      => 0,
                    'reference'     => $saleOrder->so_number,
                ]);
            }
        });

        if ((int) $saleOrder->accounting_invoice_id !== (int) $invoice->id) {
            $saleOrder->forceFill(['accounting_invoice_id' => $invoice->id])->saveQuietly();
        }

        return (int) $invoice->id;
    }

    public function syncPurchaseOrderDocument(PurchaseOrder $purchaseOrder): ?int
    {
        if (!$this->enabled()) {
            return null;
        }

        $purchaseOrder->loadMissing(['supplier', 'items.product']);
        $vendorId = $this->syncSupplier($purchaseOrder->supplier);

        if (!$vendorId) {
            return null;
        }

        $billClass = \Centrex\Accounting\Models\Bill::class;
        $bill = $purchaseOrder->accounting_bill_id
            ? $billClass::find($purchaseOrder->accounting_bill_id)
            : $billClass::where('inventory_purchase_order_id', $purchaseOrder->id)->first();

        $billDate = $purchaseOrder->ordered_at?->toDateString() ?? now()->toDateString();
        $dueDate = $purchaseOrder->expected_at?->toDateString() ?? now()->addDays(30)->toDateString();
        $status = $bill?->status?->value ?? $bill?->status ?? 'draft';
        $lockedStatuses = ['issued', 'settled', 'partially_settled', 'overdue'];

        $billData = [
            'vendor_id'                    => $vendorId,
            'bill_date'                    => $billDate,
            'due_date'                     => $dueDate,
            'subtotal'                     => round((float) $purchaseOrder->subtotal_amount, 2),
            'tax_amount'                   => round((float) $purchaseOrder->tax_amount, 2),
            'total'                        => round((float) $purchaseOrder->total_amount, 2),
            'currency'                     => $purchaseOrder->currency,
            'exchange_rate'                => (float) $purchaseOrder->exchange_rate,
            'status'                       => in_array((string) $status, $lockedStatuses, true) ? $status : 'draft',
            'notes'                        => $purchaseOrder->notes,
            'inventory_purchase_order_id'  => $purchaseOrder->id,
        ];

        if ($bill && in_array((string) $status, $lockedStatuses, true)) {
            if ((int) $purchaseOrder->accounting_bill_id !== (int) $bill->id) {
                $purchaseOrder->forceFill(['accounting_bill_id' => $bill->id])->saveQuietly();
            }

            return (int) $bill->id;
        }

        if ($bill) {
            $bill->fill($billData);
            $bill->save();
        } else {
            $bill = $billClass::create($billData);
        }

        DB::connection($bill->getConnectionName())->transaction(function () use ($bill, $purchaseOrder): void {
            $bill->items()->delete();

            foreach ($purchaseOrder->items as $item) {
                $bill->items()->create([
                    'description'   => $this->itemDescription($item->product, (float) $item->qty_ordered),
                    'itemable_type' => Product::class,
                    'itemable_id'   => $item->product_id,
                    'quantity'      => 1,
                    'unit_price'    => round((float) $item->line_total_amount, 2),
                    'tax_rate'      => 0,
                    'reference'     => $purchaseOrder->po_number,
                ]);
            }
        });

        if ((int) $purchaseOrder->accounting_bill_id !== (int) $bill->id) {
            $purchaseOrder->forceFill(['accounting_bill_id' => $bill->id])->saveQuietly();
        }

        return (int) $bill->id;
    }

    public function postStockReceipt(StockReceipt $stockReceipt): ?int
    {
        if (!$this->enabled() || $stockReceipt->accounting_journal_entry_id) {
            return $stockReceipt->accounting_journal_entry_id ? (int) $stockReceipt->accounting_journal_entry_id : null;
        }

        $stockReceipt->loadMissing('items');
        $amount = round((float) $stockReceipt->items->sum(fn ($item) => (float) $item->qty_received * (float) $item->unit_cost_amount), 2);

        if ($amount <= 0) {
            return null;
        }

        $entry = $this->createPostedJournalEntry([
            'date'          => $stockReceipt->received_at?->toDateString() ?? now()->toDateString(),
            'reference'     => $stockReceipt->grn_number,
            'type'          => 'inventory',
            'description'   => "Inventory receipt {$stockReceipt->grn_number}",
            'currency'      => config('inventory.base_currency', 'BDT'),
            'exchange_rate' => 1,
            'created_by'    => $stockReceipt->created_by,
            'source_type'   => StockReceipt::class,
            'source_id'     => $stockReceipt->id,
            'source_action' => 'stock_receipt',
            'lines'         => [
                [
                    'account_id'  => $this->accountId('inventory_asset'),
                    'type'        => 'debit',
                    'amount'      => $amount,
                    'description' => 'Inventory capitalization',
                ],
                [
                    'account_id'  => $this->accountId('goods_received_clear'),
                    'type'        => 'credit',
                    'amount'      => $amount,
                    'description' => 'Goods received not invoiced',
                ],
            ],
        ]);

        $stockReceipt->forceFill(['accounting_journal_entry_id' => $entry->id])->saveQuietly();

        return (int) $entry->id;
    }

    public function voidStockReceipt(StockReceipt $stockReceipt): void
    {
        if (!$this->enabled() || !$stockReceipt->accounting_journal_entry_id) {
            return;
        }

        $journalEntryClass = \Centrex\Accounting\Models\JournalEntry::class;
        $entry = $journalEntryClass::find($stockReceipt->accounting_journal_entry_id);

        if ($entry && (($entry->status?->value ?? $entry->status) === 'posted')) {
            $entry->void();
        }
    }

    public function postSaleFulfillment(SaleOrder $saleOrder, float $cogsAmount): ?int
    {
        if (!$this->enabled() || $cogsAmount <= 0) {
            return null;
        }

        $journalEntryClass = \Centrex\Accounting\Models\JournalEntry::class;
        $sequence = $journalEntryClass::where('source_type', SaleOrder::class)
            ->where('source_id', $saleOrder->id)
            ->where('source_action', 'sale_fulfillment')
            ->count() + 1;

        $entry = $this->createPostedJournalEntry([
            'date'          => now()->toDateString(),
            'reference'     => sprintf('%s-FUL-%02d', $saleOrder->so_number, $sequence),
            'type'          => 'inventory',
            'description'   => "COGS recognition for {$saleOrder->so_number}",
            'currency'      => config('inventory.base_currency', 'BDT'),
            'exchange_rate' => 1,
            'created_by'    => $saleOrder->created_by,
            'source_type'   => SaleOrder::class,
            'source_id'     => $saleOrder->id,
            'source_action' => 'sale_fulfillment',
            'lines'         => [
                [
                    'account_id'  => $this->accountId('cost_of_goods_sold'),
                    'type'        => 'debit',
                    'amount'      => round($cogsAmount, 2),
                    'description' => 'Cost of goods sold',
                ],
                [
                    'account_id'  => $this->accountId('inventory_asset'),
                    'type'        => 'credit',
                    'amount'      => round($cogsAmount, 2),
                    'description' => 'Inventory relief',
                ],
            ],
        ]);

        return (int) $entry->id;
    }

    public function postAdjustment(Adjustment $adjustment): ?int
    {
        if (!$this->enabled() || $adjustment->accounting_journal_entry_id) {
            return $adjustment->accounting_journal_entry_id ? (int) $adjustment->accounting_journal_entry_id : null;
        }

        $adjustment->loadMissing('items');
        $increaseAmount = 0.0;
        $decreaseAmount = 0.0;

        foreach ($adjustment->items as $item) {
            $value = round(abs((float) $item->qty_delta) * (float) $item->unit_cost_amount, 2);

            if ((float) $item->qty_delta > 0) {
                $increaseAmount += $value;
            } elseif ((float) $item->qty_delta < 0) {
                $decreaseAmount += $value;
            }
        }

        if ($increaseAmount <= 0 && $decreaseAmount <= 0) {
            return null;
        }

        $lines = [];

        if ($increaseAmount > 0) {
            $lines[] = [
                'account_id'  => $this->accountId('inventory_asset'),
                'type'        => 'debit',
                'amount'      => round($increaseAmount, 2),
                'description' => 'Inventory increase',
            ];
            $lines[] = [
                'account_id'  => $this->accountId('inventory_gain'),
                'type'        => 'credit',
                'amount'      => round($increaseAmount, 2),
                'description' => 'Inventory adjustment gain',
            ];
        }

        if ($decreaseAmount > 0) {
            $lines[] = [
                'account_id'  => $this->accountId('inventory_loss'),
                'type'        => 'debit',
                'amount'      => round($decreaseAmount, 2),
                'description' => 'Inventory adjustment loss',
            ];
            $lines[] = [
                'account_id'  => $this->accountId('inventory_asset'),
                'type'        => 'credit',
                'amount'      => round($decreaseAmount, 2),
                'description' => 'Inventory decrease',
            ];
        }

        $entry = $this->createPostedJournalEntry([
            'date'          => $adjustment->adjusted_at?->toDateString() ?? now()->toDateString(),
            'reference'     => $adjustment->adjustment_number,
            'type'          => 'inventory',
            'description'   => "Inventory adjustment {$adjustment->adjustment_number}",
            'currency'      => config('inventory.base_currency', 'BDT'),
            'exchange_rate' => 1,
            'created_by'    => $adjustment->created_by,
            'source_type'   => Adjustment::class,
            'source_id'     => $adjustment->id,
            'source_action' => 'inventory_adjustment',
            'lines'         => $lines,
        ]);

        $adjustment->forceFill(['accounting_journal_entry_id' => $entry->id])->saveQuietly();

        return (int) $entry->id;
    }

    private function createPostedJournalEntry(array $payload): \Centrex\Accounting\Models\JournalEntry
    {
        $accounting = app('accounting');
        $entry = $accounting->createJournalEntry($payload);
        $entry->post();

        return $entry;
    }

    private function accountId(string $key): int
    {
        $code = config("inventory.erp.accounting.accounts.{$key}");
        $accountClass = \Centrex\Accounting\Models\Account::class;
        $account = $accountClass::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            throw new \RuntimeException("Accounting account [{$code}] is not available for ERP integration.");
        }

        return (int) $account->id;
    }

    private function itemDescription(?Product $product, float $qty): string
    {
        $name = $product?->name ?? 'Inventory line';

        return sprintf('%s x %s', $name, rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.'));
    }
}
