<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\{PurchaseOrderStatus, SaleOrderStatus};
use Centrex\Inventory\Exceptions\InvalidTransitionException;
use Centrex\Inventory\Models\{Customer, Product, PurchaseOrder, SaleOrder, Supplier, Warehouse};
use Centrex\Inventory\Support\CommercialTeamAccess;
use Centrex\ModelData\Models\Data;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Gate, Schema};

/**
 * Cross-cutting private helpers used across several write-side domain traits:
 * status-transition assertions, transfer-box normalisation, credit-override
 * resolution and exposure calculations, default-warehouse/assignment resolution,
 * order access-control checks, and the polymorphic document-metadata (ModelData)
 * read/write pair.
 */
trait HasInventoryHelpers
{
    private function assertTransition(mixed $current, mixed $target, string $subject): void
    {
        if (!$current->canTransitionTo($target)) {
            throw new InvalidTransitionException("Cannot transition {$subject} from [{$current->value}] to [{$target->value}].");
        }
    }

    private function normalizeTransferBoxes(array $data): array
    {
        if (!empty($data['boxes'])) {
            return array_values($data['boxes']);
        }

        if (empty($data['items'])) {
            throw new \InvalidArgumentException('At least one transfer box or product line is required.');
        }

        $measuredWeight = 0.0;

        foreach ($data['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $qty = round((float) $item['qty_sent'], 4);
            $this->ensurePositiveQuantity($qty, 'qty_sent');
            $measuredWeight += $product->weight_kg !== null
                ? round($qty * (float) $product->weight_kg, 4)
                : 0.0;
        }

        return [[
            '_derived'           => true,
            'box_code'           => 'BOX-001',
            'measured_weight_kg' => round($measuredWeight, 4),
            'notes'              => $data['notes'] ?? null,
            'items'              => $data['items'],
        ]];
    }

    private function resolveCreditOverride(?Customer $customer, float $newOrderAmount, array $data): array
    {
        if (!$customer) {
            return [
                'credit_limit_amount'           => 0.0,
                'credit_exposure_before_amount' => 0.0,
                'credit_exposure_after_amount'  => 0.0,
                'credit_override_required'      => false,
                'credit_override_approved_by'   => null,
                'credit_override_approved_at'   => null,
                'credit_override_notes'         => null,
            ];
        }

        $creditLimit = round((float) $customer->credit_limit_amount, 4);
        $creditExposureBefore = $this->customerOutstandingExposure($customer->id);
        $creditExposureAfter = round($creditExposureBefore + $newOrderAmount, 4);
        $limitBreached = $creditLimit > 0
            ? $creditExposureAfter > $creditLimit + $this->qtyTolerance()
            : $creditExposureAfter > $this->qtyTolerance();

        if (!$limitBreached) {
            return [
                'credit_limit_amount'           => $creditLimit,
                'credit_exposure_before_amount' => $creditExposureBefore,
                'credit_exposure_after_amount'  => $creditExposureAfter,
                'credit_override_required'      => false,
                'credit_override_approved_by'   => null,
                'credit_override_approved_at'   => null,
                'credit_override_notes'         => null,
            ];
        }

        // Credit limit breached: flag the order for review rather than blocking it.
        // Approve automatically only when the submitter holds the approve-credit gate.
        $approvedBy = $data['credit_override_approved_by'] ?? $data['created_by'] ?? $this->currentUserId();
        $canApprove = $this->canApproveCreditOverride($approvedBy);

        return [
            'credit_limit_amount'           => $creditLimit,
            'credit_exposure_before_amount' => $creditExposureBefore,
            'credit_exposure_after_amount'  => $creditExposureAfter,
            'credit_override_required'      => true,
            'credit_override_approved_by'   => $canApprove ? $approvedBy : null,
            'credit_override_approved_at'   => $canApprove ? now() : null,
            'credit_override_notes'         => $data['credit_override_notes'] ?? null,
        ];
    }

    private function customerOutstandingExposure(int $customerId): float
    {
        // Open orders (confirmed but not yet delivered): always count due_amount.
        // due_amount is set at creation (= total_amount) and reduced by InvoicePaymentObserver
        // whenever a linked accounting invoice receives a payment.
        $openStatuses = [
            SaleOrderStatus::CONFIRMED->value,
            SaleOrderStatus::PROCESSING->value,
            SaleOrderStatus::PARTIAL->value,
        ];

        $openExposure = (float) SaleOrder::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $openStatuses)
            ->sum('due_amount');

        // FULFILLED orders: only count those with a linked accounting invoice.
        // Without an invoice the goods were delivered without credit (COD/cash),
        // so there is no outstanding receivable to track.
        // due_amount on these rows is kept current by InvoicePaymentObserver.
        $fulfilledExposure = (float) SaleOrder::query()
            ->where('customer_id', $customerId)
            ->where('status', SaleOrderStatus::FULFILLED->value)
            ->whereNotNull('accounting_invoice_id')
            ->sum('due_amount');

        return round($openExposure + $fulfilledExposure, 4);
    }

    private function supplierOutstandingExposure(int $supplierId): float
    {
        // Open purchase orders (confirmed but not yet fully received): always count due_amount.
        // due_amount is set at creation (= total_amount) and reduced by BillPaymentObserver
        // whenever a linked accounting bill receives a payment.
        $openStatuses = [
            PurchaseOrderStatus::CONFIRMED->value,
            PurchaseOrderStatus::PARTIAL->value,
        ];

        $openExposure = (float) PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', $openStatuses)
            ->sum('due_amount');

        // RECEIVED orders: only count those with a linked accounting bill.
        // Without a bill the goods were received without credit terms, so there is no
        // outstanding payable to track. due_amount on these rows is kept current by
        // BillPaymentObserver.
        $receivedExposure = (float) PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseOrderStatus::RECEIVED->value)
            ->whereNotNull('accounting_bill_id')
            ->sum('due_amount');

        return round($openExposure + $receivedExposure, 4);
    }

    private function canApproveCreditOverride(?int $approvedBy): bool
    {
        if (auth()->check()) {
            return Gate::forUser(auth()->user())->allows('inventory.sale-orders.approve-credit');
        }

        return $approvedBy !== null;
    }

    private function currentUserId(): ?int
    {
        $user = auth()->user();

        if (!$user || !method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }

    private function defaultPurchaseWarehouseId(): int
    {
        $name = (string) config('inventory.purchase_defaults.warehouse_name', 'UK');

        $warehouse = Warehouse::query()->where('name', $name)->first();

        if (!$warehouse) {
            throw new \RuntimeException("Default purchase warehouse [{$name}] not found.");
        }

        return (int) $warehouse->id;
    }

    private function defaultSaleWarehouseId(): int
    {
        $name = (string) config('inventory.sale_defaults.warehouse_name', 'UK');

        $warehouse = Warehouse::query()->where('name', $name)->first();

        if (!$warehouse) {
            throw new \RuntimeException("Default sale warehouse [{$name}] not found.");
        }

        return (int) $warehouse->id;
    }

    private function salesAssignment(array $data, ?Customer $customer, ?int $createdBy): array
    {
        $assignment = CommercialTeamAccess::assignmentFor('sales', $createdBy);
        $explicit = array_filter([
            'sales_manager_id'           => $data['sales_manager_id'] ?? $customer?->sales_manager_id ?? null,
            'sales_assistant_manager_id' => $data['sales_assistant_manager_id'] ?? $customer?->sales_assistant_manager_id ?? null,
            'sales_executive_id'         => $data['sales_executive_id'] ?? $customer?->sales_executive_id ?? null,
        ], fn ($value): bool => $value !== null);

        return array_replace($assignment, $explicit);
    }

    private function purchaseAssignment(array $data, ?int $createdBy): array
    {
        $supplier = isset($data['supplier_id']) ? Supplier::query()->find((int) $data['supplier_id']) : null;
        $assignment = CommercialTeamAccess::assignmentFor('purchase', $createdBy);
        $explicit = array_filter([
            'purchase_manager_id'           => $data['purchase_manager_id'] ?? $supplier?->purchase_manager_id ?? null,
            'purchase_assistant_manager_id' => $data['purchase_assistant_manager_id'] ?? $supplier?->purchase_assistant_manager_id ?? null,
            'purchase_executive_id'         => $data['purchase_executive_id'] ?? $supplier?->purchase_executive_id ?? null,
        ], fn ($value): bool => $value !== null);

        return array_replace($assignment, $explicit);
    }

    private function customerSegment(float $revenue, int $ordersCount): string
    {
        return match (true) {
            $revenue >= 500000 || $ordersCount >= 20 => 'Strategic',
            $revenue >= 100000 || $ordersCount >= 6  => 'Growth',
            $ordersCount > 1                         => 'Repeat',
            default                                  => 'New',
        };
    }

    private function assertSaleOrderAccess(SaleOrder $saleOrder): void
    {
        $visibleUserIds = CommercialTeamAccess::visibleUserIds('sales');

        if ($visibleUserIds === null) {
            return;
        }

        $ownerIds = collect([
            $saleOrder->created_by,
            $saleOrder->sales_manager_id,
            $saleOrder->sales_assistant_manager_id,
            $saleOrder->sales_executive_id,
        ])->filter()->map(fn ($id): int => (int) $id)->all();

        abort_unless(count(array_intersect($visibleUserIds, $ownerIds)) > 0, 403);
    }

    private function assertPurchaseOrderAccess(PurchaseOrder $purchaseOrder): void
    {
        $visibleUserIds = CommercialTeamAccess::visibleUserIds('purchase');

        if ($visibleUserIds === null) {
            return;
        }

        $ownerIds = collect([
            $purchaseOrder->created_by,
            $purchaseOrder->purchase_manager_id,
            $purchaseOrder->purchase_assistant_manager_id,
            $purchaseOrder->purchase_executive_id,
        ])->filter()->map(fn ($id): int => (int) $id)->all();

        abort_unless(count(array_intersect($visibleUserIds, $ownerIds)) > 0, 403);
    }

    private function normalizeSaleDocumentType(?string $documentType): string
    {
        return $documentType === 'quotation' ? 'quotation' : 'order';
    }

    private function normalizePurchaseDocumentType(?string $documentType): string
    {
        return $documentType === 'requisition' ? 'requisition' : 'order';
    }

    private function appendConversionNote(?string $notes, string $line): string
    {
        return collect([$notes, $line])
            ->filter(fn (?string $value): bool => filled($value))
            ->implode("\n\n");
    }

    private function modelDataReady(): bool
    {
        return class_exists(Data::class)
            && Schema::hasTable('model_datas');
    }

    private function documentMetadata(Model $model): array
    {
        if (!$this->modelDataReady()) {
            return [];
        }

        $record = Data::query()
            ->forModel($model)
            ->first();

        if (!$record) {
            return [];
        }

        return is_array($record->data)
            ? $record->data
            : (json_decode((string) $record->data, true) ?: []);
    }

    private function putDocumentMetadata(Model $model, array $metadata): void
    {
        if (!$this->modelDataReady()) {
            return;
        }

        Data::putForModel($model, $metadata);
    }
}
