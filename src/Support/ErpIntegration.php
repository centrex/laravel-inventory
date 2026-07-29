<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Enums\StockReceiptStatus;
use Centrex\Inventory\Models\{Adjustment, Customer as InventoryCustomer, Product, PurchaseOrder, PurchaseReturn, SaleOrder, SaleReturn, Shipment, StockReceipt, Supplier as InventorySupplier, Transfer};
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
            'code'              => $customer->code,
            'name'              => $customer->name,
            'organization_name' => $customer->organization_name,
            'email'             => $customer->email,
            'phone'             => $customer->phone,
            'currency'          => $customer->currency ?? config('inventory.base_currency', 'BDT'),
            'is_active'         => (bool) $customer->is_active,
            'modelable_type'    => InventoryCustomer::class,
            'modelable_id'      => $customer->id,
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
        if (!$this->enabled()) {
            return null;
        }

        $saleOrder->loadMissing(['customer', 'warehouse', 'items']);
        $saleOrder->items->loadMissing('product');
        $customerId = $saleOrder->customer_id
            ? $this->syncCustomer($saleOrder->customer)
            : $this->resolveWalkInAccountingCustomerId();

        if (!$customerId) {
            return null;
        }

        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;
        $invoice = $saleOrder->accounting_invoice_id
            ? $invoiceClass::find($saleOrder->accounting_invoice_id)
            : $invoiceClass::query()
                ->where(fn ($query) => $query
                    ->where('source_type', SaleOrder::class)
                    ->where('source_id', $saleOrder->id))
                ->orWhere('inventory_sale_order_id', $saleOrder->id)
                ->first();

        $invoiceDate = $saleOrder->ordered_at?->toDateString() ?? now()->toDateString();
        $dueDate = $saleOrder->ordered_at?->copy()->addDays(30)->toDateString() ?? now()->addDays(30)->toDateString();
        $status = $invoice?->status?->value ?? $invoice?->status ?? 'draft';
        $lockedStatuses = ['issued', 'settled', 'partially_settled', 'overdue'];

        $invoiceData = [
            'customer_id'     => $customerId,
            'invoice_date'    => $invoiceDate,
            'due_date'        => $dueDate,
            'subtotal'        => round((float) $saleOrder->subtotal_amount, 2),
            'tax_amount'      => round((float) $saleOrder->tax_amount, 2),
            'discount_amount' => round((float) $saleOrder->discount_amount + (float) $saleOrder->coupon_discount_amount, 2),
            'shipping_amount' => round((float) $saleOrder->shipping_amount, 2),
            'total'           => round((float) $saleOrder->total_amount, 2),
            // The amounts above are already converted to base currency (see SaleOrder::total_amount
            // et al.), so the invoice must be booked in base currency too — otherwise Invoice's
            // convertToBase()/base_* accessors and the due-amount resync below would convert twice.
            // The sale order's own currency/exchange_rate remain available on $saleOrder for display.
            'currency'                => config('inventory.base_currency', 'BDT'),
            'exchange_rate'           => 1.0,
            'status'                  => in_array((string) $status, $lockedStatuses, true) ? $status : 'draft',
            'notes'                   => $saleOrder->notes,
            'source_type'             => SaleOrder::class,
            'source_id'               => $saleOrder->id,
            'source_reference'        => $saleOrder->so_number,
            'sbu_code'                => $this->resolveDocumentSbuCode($saleOrder->warehouse, $saleOrder->customer),
            'inventory_sale_order_id' => $saleOrder->id,
        ];

        if ($invoice && in_array((string) $status, $lockedStatuses, true)) {
            if ((int) $saleOrder->accounting_invoice_id !== (int) $invoice->id) {
                $saleOrder->forceFill(['accounting_invoice_id' => $invoice->id])->saveQuietly();
                $this->resyncSaleOrderDueAmount($saleOrder, $invoice);
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
                    'quantity'      => (int) $item->qty_ordered,
                    'unit_price'    => round((float) $item->unit_price_local, 2),
                    'amount'        => round((float) $item->line_total_local, 2),
                    'tax_rate'      => 0,
                    'tax_amount'    => 0,
                    'reference'     => $saleOrder->so_number,
                ]);
            }
        });

        if ((int) $saleOrder->accounting_invoice_id !== (int) $invoice->id) {
            $saleOrder->forceFill(['accounting_invoice_id' => $invoice->id])->saveQuietly();
        }

        // The invoice total may have just changed above (e.g. the sale order was edited),
        // so always resync — not only on the first link — or due_amount goes stale.
        $this->resyncSaleOrderDueAmount($saleOrder, $invoice);

        return (int) $invoice->id;
    }

    /**
     * Void the invoice linked to a cancelled sale order, reversing its journal entry if one
     * was posted. Invoices that have already received a payment are left untouched — voiding
     * would misrepresent money that was actually collected — so cancellation doesn't silently
     * erase that; it needs a manual accounting decision instead.
     */
    public function voidSaleOrderInvoice(SaleOrder $saleOrder): void
    {
        if (!$this->enabled() || !$saleOrder->accounting_invoice_id) {
            return;
        }

        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;
        $invoice = $invoiceClass::find($saleOrder->accounting_invoice_id);

        if (!$invoice) {
            return;
        }

        $status = $invoice->status instanceof \BackedEnum ? $invoice->status->value : (string) $invoice->status;

        if ($status === 'void' || (float) $invoice->paid_amount > 0) {
            return;
        }

        DB::connection($invoice->getConnectionName())->transaction(function () use ($invoice): void {
            if ($invoice->journal_entry_id) {
                $entry = \Centrex\Accounting\Models\JournalEntry::find($invoice->journal_entry_id);
                $entryStatus = $entry?->status instanceof \BackedEnum ? $entry->status->value : (string) $entry?->status;

                if ($entry && $entryStatus === 'posted') {
                    $entry->void();
                }
            }

            $invoice->update(['status' => 'void']);
        });
    }

    public function syncPurchaseOrderDocument(PurchaseOrder $purchaseOrder): ?int
    {
        if (!$this->enabled()) {
            return null;
        }

        $purchaseOrder->loadMissing(['supplier', 'warehouse', 'items']);
        $purchaseOrder->items->loadMissing('product');
        $vendorId = $this->syncSupplier($purchaseOrder->supplier);

        if (!$vendorId) {
            return null;
        }

        $billClass = \Centrex\Accounting\Models\Bill::class;
        $bill = $purchaseOrder->accounting_bill_id
            ? $billClass::find($purchaseOrder->accounting_bill_id)
            : $billClass::query()
                ->where(fn ($query) => $query
                    ->where('source_type', PurchaseOrder::class)
                    ->where('source_id', $purchaseOrder->id))
                ->orWhere('inventory_purchase_order_id', $purchaseOrder->id)
                ->first();

        $billDate = $purchaseOrder->ordered_at?->toDateString() ?? now()->toDateString();
        $dueDate = $purchaseOrder->expected_at?->toDateString() ?? now()->addDays(30)->toDateString();
        $status = $bill?->status?->value ?? $bill?->status ?? 'draft';
        $lockedStatuses = ['issued', 'settled', 'partially_settled', 'overdue'];

        $billData = [
            'vendor_id'            => $vendorId,
            'bill_date'            => $billDate,
            'due_date'             => $dueDate,
            'subtotal'             => round((float) $purchaseOrder->subtotal_amount, 2),
            'tax_amount'           => round((float) $purchaseOrder->tax_amount, 2),
            'discount_amount'      => round((float) $purchaseOrder->discount_amount, 2),
            'shipping_amount'      => round((float) $purchaseOrder->shipping_amount, 2),
            'other_charges_amount' => round((float) $purchaseOrder->other_charges_amount, 2),
            'grni_clearing_amount' => $this->grniClearingAmountFor($purchaseOrder),
            'total'                => round((float) $purchaseOrder->total_amount, 2),
            // Same rationale as syncSaleOrderDocument(): these amounts are already in base
            // currency, so the bill must be booked in base currency to avoid double conversion.
            'currency'                    => config('inventory.base_currency', 'BDT'),
            'exchange_rate'               => 1.0,
            'status'                      => in_array((string) $status, $lockedStatuses, true) ? $status : 'draft',
            'notes'                       => $purchaseOrder->notes,
            'source_type'                 => PurchaseOrder::class,
            'source_id'                   => $purchaseOrder->id,
            'source_reference'            => $purchaseOrder->po_number,
            'sbu_code'                    => $this->resolveDocumentSbuCode($purchaseOrder->warehouse, $purchaseOrder->supplier),
            'inventory_purchase_order_id' => $purchaseOrder->id,
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
                    'quantity'      => (int) $item->qty_ordered,
                    'unit_price'    => round((float) $item->unit_price_local, 2),
                    'amount'        => round((float) $item->line_total_local, 2),
                    'tax_rate'      => 0,
                    'tax_amount'    => 0,
                    'reference'     => $purchaseOrder->po_number,
                ]);
            }
        });

        if ((int) $purchaseOrder->accounting_bill_id !== (int) $bill->id) {
            $purchaseOrder->forceFill(['accounting_bill_id' => $bill->id])->saveQuietly();
        }

        return (int) $bill->id;
    }

    /**
     * Create/update a draft vendor Bill for the freight/courier cost of an inter-warehouse
     * transfer, against the courier Supplier linked on the transfer. Mirrors
     * syncPurchaseOrderDocument() — posting (Accounting::postBill()) stays a manual step in the
     * accounting Bills UI, same as purchase order bills.
     */
    public function syncTransferDocument(Transfer $transfer): ?int
    {
        return $this->syncFreightBillDocument($transfer, $transfer->transfer_number);
    }

    /**
     * Create/update a draft vendor Bill for the freight/courier cost of a box-tracked
     * inter-warehouse shipment. See syncTransferDocument().
     */
    public function syncShipmentDocument(Shipment $shipment): ?int
    {
        return $this->syncFreightBillDocument($shipment, $shipment->shipment_number);
    }

    /**
     * Shared implementation for syncTransferDocument() / syncShipmentDocument(). The freight cost
     * is already capitalized into destination WAC via landed-cost allocation (see
     * dispatchTransfer()/receiveTransfer() and their shipment equivalents in Inventory.php) but no
     * liability to the courier has ever been booked, so Accounting::postBill()'s default
     * DR Inventory Asset / CR Accounts Payable entry is exactly the right one here — no changes
     * needed in laravel-accounting.
     */
    private function syncFreightBillDocument(Transfer|Shipment $document, string $documentNumber): ?int
    {
        if (!$this->enabled()) {
            return null;
        }

        $document->loadMissing('supplier');
        $vendorId = $this->syncSupplier($document->supplier);

        if (!$vendorId) {
            return null;
        }

        // Shipment carries customs/handling/insurance in addition to weight-based freight;
        // Transfer only ever has shipping_cost_amount — these all read 0 for a Transfer.
        $freightAmount = round((float) $document->shipping_cost_amount, 2);
        $customsAmount = $document instanceof Shipment ? round((float) $document->customs_amount, 2) : 0.0;
        $handlingAmount = $document instanceof Shipment ? round((float) $document->handling_amount, 2) : 0.0;
        $insuranceAmount = $document instanceof Shipment ? round((float) $document->insurance_amount, 2) : 0.0;
        $amount = round($freightAmount + $customsAmount + $handlingAmount + $insuranceAmount, 2);

        if ($amount <= 0) {
            return null;
        }

        $documentClass = $document::class;
        $billClass = \Centrex\Accounting\Models\Bill::class;
        $bill = $document->accounting_bill_id
            ? $billClass::find($document->accounting_bill_id)
            : $billClass::query()
                ->where('source_type', $documentClass)
                ->where('source_id', $document->id)
                ->first();

        if ($bill && $bill->journal_entry_id !== null) {
            if ((int) $document->accounting_bill_id !== (int) $bill->id) {
                $document->forceFill(['accounting_bill_id' => $bill->id])->saveQuietly();
            }

            return (int) $bill->id;
        }

        $dispatchedAt = $document instanceof Transfer ? $document->dispatched_at : null;

        $billData = [
            'vendor_id'        => $vendorId,
            'bill_date'        => $dispatchedAt?->toDateString() ?? $document->shipped_at?->toDateString() ?? now()->toDateString(),
            'due_date'         => now()->addDays(30)->toDateString(),
            'subtotal'         => $amount,
            'tax_amount'       => 0,
            'total'            => $amount,
            'currency'         => config('inventory.base_currency', 'BDT'),
            'exchange_rate'    => 1.0,
            'status'           => 'draft',
            'notes'            => "Freight/customs/handling charges for {$documentNumber}",
            'source_type'      => $documentClass,
            'source_id'        => $document->id,
            'source_reference' => $documentNumber,
        ];

        if ($bill) {
            $bill->fill($billData);
            $bill->save();
        } else {
            $bill = $billClass::create($billData);
        }

        DB::connection($bill->getConnectionName())->transaction(function () use ($bill, $documentNumber, $freightAmount, $customsAmount, $handlingAmount, $insuranceAmount): void {
            $bill->items()->delete();

            $lines = [
                'Freight / courier charge' => $freightAmount,
                'Customs duty'             => $customsAmount,
                'Handling charge'          => $handlingAmount,
                'Insurance'                => $insuranceAmount,
            ];

            foreach ($lines as $label => $lineAmount) {
                if ($lineAmount <= 0) {
                    continue;
                }

                $bill->items()->create([
                    'description' => "{$label} — {$documentNumber}",
                    'quantity'    => 1,
                    'unit_price'  => $lineAmount,
                    'amount'      => $lineAmount,
                    'tax_rate'    => 0,
                    'tax_amount'  => 0,
                    'reference'   => $documentNumber,
                ]);
            }
        });

        if ((int) $document->accounting_bill_id !== (int) $bill->id) {
            $document->forceFill(['accounting_bill_id' => $bill->id])->saveQuietly();
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

    /**
     * Post the accounting effect of a customer return: always reverses the inventory/COGS
     * pairing that fulfillment recognized, and — only when the originating sale order has a
     * posted accounting invoice — raises and issues a CreditMemo for the returned revenue.
     * The memo (not this integration) owns the DR Sales Returns / CR AR journal entry, gives
     * the return a numbered document, and tracks any later cash refund
     * (see Accounting::issueCreditMemo() / recordCreditMemoRefund()).
     *
     * If the invoice isn't posted yet (still draft), the credit memo is skipped here — there's
     * no AR entry yet to credit against — and picked up later by
     * reconcileSaleReturnsForInvoice(), which InventoryServiceProvider wires to fire on
     * Centrex\Accounting\Events\InvoicePosted. issueSaleReturnCreditMemo() is shared by both
     * paths and is idempotent, so a return posted after its invoice (the common case) and one
     * posted before it (caught up later) both end up with exactly one credit memo either way.
     */
    public function postSaleReturn(SaleReturn $saleReturn): ?int
    {
        if (!$this->enabled() || $saleReturn->accounting_journal_entry_id) {
            return $saleReturn->accounting_journal_entry_id ? (int) $saleReturn->accounting_journal_entry_id : null;
        }

        $saleReturn->loadMissing(['items', 'saleOrder']);

        $totalCost = round((float) $saleReturn->items->sum(fn ($item) => (float) $item->qty_returned * (float) $item->unit_cost_amount), 2);

        $entryId = null;

        if ($totalCost > 0) {
            $entry = $this->createPostedJournalEntry([
                'date'          => $saleReturn->returned_at?->toDateString() ?? now()->toDateString(),
                'reference'     => $saleReturn->return_number,
                'type'          => 'inventory',
                'description'   => "Customer return {$saleReturn->return_number}",
                'currency'      => config('inventory.base_currency', 'BDT'),
                'exchange_rate' => 1,
                'created_by'    => $saleReturn->created_by,
                'source_type'   => SaleReturn::class,
                'source_id'     => $saleReturn->id,
                'source_action' => 'sale_return',
                'lines'         => [
                    [
                        'account_id'  => $this->accountId('inventory_asset'),
                        'type'        => 'debit',
                        'amount'      => $totalCost,
                        'description' => 'Inventory restocked from customer return',
                    ],
                    [
                        'account_id'  => $this->accountId('cost_of_goods_sold'),
                        'type'        => 'credit',
                        'amount'      => $totalCost,
                        'description' => 'COGS reversal for customer return',
                    ],
                ],
            ]);

            $saleReturn->forceFill(['accounting_journal_entry_id' => $entry->id])->saveQuietly();
            $entryId = (int) $entry->id;
        }

        $invoice = $this->postedInvoiceFor($saleReturn->saleOrder);

        if ($invoice) {
            $this->issueSaleReturnCreditMemo($saleReturn, $invoice);
        }

        return $entryId;
    }

    /**
     * Catch-up pass for sale returns posted while their sale order's invoice was still draft
     * (see postSaleReturn()'s docblock). Bound to Centrex\Accounting\Events\InvoicePosted in
     * InventoryServiceProvider, so this runs the moment that invoice is actually posted.
     */
    public function reconcileSaleReturnsForInvoice(\Centrex\Accounting\Models\Invoice $invoice): void
    {
        if (!$this->enabled()) {
            return;
        }

        $saleOrder = SaleOrder::query()->where('accounting_invoice_id', $invoice->id)->first();

        if (!$saleOrder) {
            return;
        }

        SaleReturn::query()
            ->where('sale_order_id', $saleOrder->id)
            ->where('status', 'posted')
            ->with('items')
            ->get()
            ->each(fn (SaleReturn $saleReturn) => $this->issueSaleReturnCreditMemo($saleReturn, $invoice));
    }

    /**
     * Idempotent: skips if a non-void credit memo already exists for this return
     * (source_type/source_id), so it's safe to call from both postSaleReturn() (when the
     * invoice happens to already be posted) and reconcileSaleReturnsForInvoice() (the
     * deferred catch-up) without risking a duplicate memo for the same return.
     */
    private function issueSaleReturnCreditMemo(SaleReturn $saleReturn, \Centrex\Accounting\Models\Invoice $invoice): void
    {
        $saleReturn->loadMissing(['items', 'saleOrder']);

        $totalRevenue = round((float) $saleReturn->items->sum(fn ($item) => (float) $item->qty_returned * (float) $item->unit_price_amount), 2);

        if ($totalRevenue <= 0) {
            return;
        }

        $alreadyCredited = \Centrex\Accounting\Models\CreditMemo::query()
            ->where('source_type', SaleReturn::class)
            ->where('source_id', $saleReturn->id)
            ->whereNotIn('status', ['void'])
            ->exists();

        if ($alreadyCredited) {
            return;
        }

        $accounting = app('accounting');
        $creditMemo = $accounting->createCreditMemo($invoice, [
            'date'             => $saleReturn->returned_at?->toDateString() ?? now()->toDateString(),
            'reason'           => "Customer return {$saleReturn->return_number}",
            'subtotal'         => $totalRevenue,
            'tax_amount'       => 0,
            'source_type'      => SaleReturn::class,
            'source_id'        => $saleReturn->id,
            'source_reference' => $saleReturn->return_number,
            'created_by'       => $saleReturn->created_by,
        ]);
        $accounting->issueCreditMemo($creditMemo);

        if ($saleReturn->saleOrder) {
            $this->resyncSaleOrderDueAmount($saleReturn->saleOrder, $invoice->fresh());
        }
    }

    /**
     * Post the accounting effect of a return to a supplier: only when the originating purchase
     * order has a posted accounting bill, reverses the exact Inventory Asset / Accounts Payable
     * pairing that bill recognized, for the returned cost. Without a posted bill there is no AP
     * balance to reduce, so nothing is posted.
     */
    public function postPurchaseReturn(PurchaseReturn $purchaseReturn): ?int
    {
        if (!$this->enabled() || $purchaseReturn->accounting_journal_entry_id) {
            return $purchaseReturn->accounting_journal_entry_id ? (int) $purchaseReturn->accounting_journal_entry_id : null;
        }

        $purchaseReturn->loadMissing(['items', 'purchaseOrder']);

        $totalCost = round((float) $purchaseReturn->items->sum(fn ($item) => (float) $item->qty_returned * (float) $item->unit_cost_amount), 2);
        $bill = $this->postedBillFor($purchaseReturn->purchaseOrder);

        if (!$bill || $totalCost <= 0) {
            return null;
        }

        $entry = $this->createPostedJournalEntry([
            'date'          => $purchaseReturn->returned_at?->toDateString() ?? now()->toDateString(),
            'reference'     => $purchaseReturn->return_number,
            'type'          => 'inventory',
            'description'   => "Supplier return {$purchaseReturn->return_number}",
            'currency'      => config('inventory.base_currency', 'BDT'),
            'exchange_rate' => 1,
            'created_by'    => $purchaseReturn->created_by,
            'source_type'   => PurchaseReturn::class,
            'source_id'     => $purchaseReturn->id,
            'source_action' => 'purchase_return',
            'lines'         => [
                [
                    'account_id'  => $this->accountId('accounts_payable'),
                    'type'        => 'debit',
                    'amount'      => $totalCost,
                    'description' => 'AP reduced for supplier return',
                ],
                [
                    'account_id'  => $this->accountId('inventory_asset'),
                    'type'        => 'credit',
                    'amount'      => $totalCost,
                    'description' => 'Inventory relieved for supplier return',
                ],
            ],
        ]);

        $purchaseReturn->forceFill(['accounting_journal_entry_id' => $entry->id])->saveQuietly();

        // Audit/ledger record — not linked to $entry above (already booked as a line in it);
        // this is what VendorLedger reads to show the credit against the bill's balance.
        \Centrex\Accounting\Models\Expense::create([
            'chargeable_type' => \Centrex\Accounting\Models\Bill::class,
            'chargeable_id'   => $bill->id,
            'account_id'      => $this->accountId('purchase_returns'),
            'expense_date'    => $purchaseReturn->returned_at?->toDateString() ?? now()->toDateString(),
            'subtotal'        => $totalCost,
            'tax_amount'      => 0,
            'total'           => $totalCost,
            'paid_amount'     => $totalCost,
            'currency'        => config('inventory.base_currency', 'BDT'),
            'status'          => 'paid',
            'payment_method'  => 'purchase_return',
            'reference'       => $purchaseReturn->return_number,
            'notes'           => "Supplier return {$purchaseReturn->return_number}",
        ]);

        return (int) $entry->id;
    }

    /** The linked accounting invoice for a sale order, only if it has actually been posted. */
    private function postedInvoiceFor(?SaleOrder $saleOrder): ?\Centrex\Accounting\Models\Invoice
    {
        if (!$saleOrder?->accounting_invoice_id) {
            return null;
        }

        $invoice = \Centrex\Accounting\Models\Invoice::find($saleOrder->accounting_invoice_id);

        return $invoice && $invoice->journal_entry_id !== null ? $invoice : null;
    }

    /** The linked accounting bill for a purchase order, only if it has actually been posted. */
    private function postedBillFor(?PurchaseOrder $purchaseOrder): ?\Centrex\Accounting\Models\Bill
    {
        if (!$purchaseOrder?->accounting_bill_id) {
            return null;
        }

        $bill = \Centrex\Accounting\Models\Bill::find($purchaseOrder->accounting_bill_id);

        return $bill && $bill->journal_entry_id !== null ? $bill : null;
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

    private function resolveDocumentSbuCode(mixed ...$models): ?string
    {
        foreach ($models as $model) {
            $sbuCode = $this->resolveModelSbuCode($model);

            if ($sbuCode !== null) {
                return $sbuCode;
            }
        }

        return null;
    }

    private function resolveModelSbuCode(mixed $model): ?string
    {
        if (!is_object($model)) {
            return null;
        }

        $meta = method_exists($model, 'getAttributes') && array_key_exists('meta', $model->getAttributes())
            ? $model->getAttribute('meta')
            : ($model->meta ?? null);

        if (!is_array($meta)) {
            return null;
        }

        $value = strtoupper(trim((string) ($meta['default_sbu'] ?? $meta['sbu_code'] ?? $meta['sbu'] ?? '')));

        return $value !== '' ? $value : null;
    }

    private function resolveWalkInAccountingCustomerId(): ?int
    {
        if (!$this->enabled()) {
            return null;
        }

        $accountingCustomerClass = \Centrex\Accounting\Models\Customer::class;
        $customer = $accountingCustomerClass::query()->firstOrCreate(
            ['code' => 'WALK-IN'],
            [
                'name'      => 'Walk-in Customer',
                'currency'  => config('inventory.base_currency', 'BDT'),
                'is_active' => true,
            ],
        );

        return (int) $customer->id;
    }

    private function resyncSaleOrderDueAmount(SaleOrder $saleOrder, object $invoice): void
    {
        // $invoice->total/paid_amount are already in base currency (see syncSaleOrderDocument()),
        // same as $saleOrder->due_amount/paid_amount — no rate conversion needed here.
        //
        // Use Invoice::$balance rather than total-paid_amount: balance already nets out AR-reducing
        // discounts (accounts 6130-6133) and issued credit memos (Invoice::getBalanceAttribute()),
        // which total-paid_amount alone ignores — without this, posting a sale-return credit memo
        // never moved the sale order's due_amount, since nothing here read the credit at all.
        $due = round(max(0.0, (float) $invoice->balance), 4);
        $paid = round(max(0.0, (float) $invoice->paid_amount), 4);
        $saleOrder->forceFill(['due_amount' => $due, 'paid_amount' => $paid])->saveQuietly();
    }

    /**
     * Sum of what's already been debited to Inventory Asset via posted GRNs for this PO — the same
     * formula postStockReceipt() uses for its own JE amount. When the vendor bill is later posted,
     * Accounting::postBill() clears this amount against the GRNI liability instead of re-debiting
     * Inventory, so the same goods aren't capitalized twice (once via the GRN, again via the bill).
     */
    private function grniClearingAmountFor(PurchaseOrder $purchaseOrder): float
    {
        $amount = StockReceipt::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('status', StockReceiptStatus::POSTED)
            ->with('items')
            ->get()
            ->sum(fn (StockReceipt $grn) => $grn->items->sum(
                fn ($item) => (float) $item->qty_received * (float) $item->unit_cost_amount,
            ));

        return round((float) $amount, 2);
    }
}
