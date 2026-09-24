<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\{PriceTierCode, SaleOrderStatus};
use Centrex\Inventory\Exceptions\InvalidTransitionException;
use Centrex\Inventory\Jobs\SyncSaleOrderAccountingDocumentJob;
use Centrex\Inventory\Models\{Coupon, Customer, SaleOrder, SaleOrderItem};
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

trait ManagesSaleOrders
{
    public function createSaleOrder(array $data): SaleOrder
    {
        // Resolved before the transaction opens: getExchangeRate() can fall through to a live
        // HTTP call to the Open Exchange Rates API (see resolveExchangeRate()) when no fresh
        // stored rate covers today — doing that while holding the transaction (and, downstream,
        // createWithSequentialNumber()'s range lock on the so_number sequence) turns one slow
        // API call into a lock held across every concurrent sale order creation, not just this one.
        $currency = strtoupper($data['currency'] ?? config('inventory.sale_defaults.currency', 'GBP'));
        $rate = (float) ($data['exchange_rate'] ?? $this->getExchangeRate($currency));

        $so = DB::transaction(function () use ($data, $currency, $rate): SaleOrder {
            $tierCode = $this->normalizePriceTierCode($data['price_tier_code'] ?? PriceTierCode::B2B_RETAIL->value);
            $warehouseId = $data['warehouse_id'] ?? $this->defaultSaleWarehouseId();
            $customer = isset($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
            $documentType = $this->normalizeSaleDocumentType($data['document_type'] ?? null);
            $orderedAt = Carbon::parse($data['ordered_at'] ?? now());

            $taxLocal = (float) ($data['tax_local'] ?? 0);
            $discountLocal = (float) ($data['discount_local'] ?? 0);
            $shippingLocal = (float) ($data['shipping_local'] ?? 0);
            $lineItems = [];
            $subtotalLocal = 0.0;

            // Loaded once for every product on the order rather than via resolvePrice()'s up to
            // 4 sequential fallback queries per line: a multi-line order (the common case from
            // the mobile app, which rarely sends unit_price_local) was otherwise issuing dozens
            // of blocking queries per checkout while holding this transaction's row locks.
            $priceCandidatesByProduct = $this->loadPriceCandidatesForProducts(
                collect($data['items'])->map(fn (array $item): int => (int) $item['product_id'])->unique()->values(),
            );

            foreach ($data['items'] as $item) {
                [$productId, $variantId] = $this->resolveProductReference($item);
                $itemTierCode = isset($item['price_tier_code']) && trim((string) $item['price_tier_code']) !== ''
                    ? $this->normalizePriceTierCode($item['price_tier_code'])
                    : $tierCode;

                $fromDamaged = (bool) ($item['from_damaged'] ?? false);

                $unitPriceBdt = isset($item['unit_price_local'])
                    ? round((float) $item['unit_price_local'] * $rate, 4)
                    : (float) $this->pickPrice(
                        $priceCandidatesByProduct->get($productId, collect()),
                        $productId,
                        $itemTierCode,
                        $warehouseId,
                        $variantId,
                        $fromDamaged,
                    )->price_amount;

                $unitPriceLocal = round($unitPriceBdt / ($rate ?: 1), 4);
                $qty = (float) $item['qty_ordered'];
                $discountPct = (float) ($item['discount_pct'] ?? 0);
                $lineTotalLocal = round($qty * $unitPriceLocal * (1 - $discountPct / 100), 4);
                $lineTotalBdt = round($lineTotalLocal * $rate, 4);
                $subtotalLocal += $lineTotalLocal;

                $lineItems[] = [
                    'product_id'        => $productId,
                    'variant_id'        => $variantId,
                    'price_tier_code'   => $itemTierCode,
                    'qty_ordered'       => $qty,
                    'qty_fulfilled'     => 0,
                    'from_damaged'      => $fromDamaged,
                    'unit_price_local'  => $unitPriceLocal,
                    'unit_price_amount' => $unitPriceBdt,
                    'unit_cost_amount'  => 0,
                    'discount_pct'      => $discountPct,
                    'line_total_local'  => $lineTotalLocal,
                    'line_total_amount' => $lineTotalBdt,
                    'notes'             => $item['notes'] ?? null,
                ];
            }

            $subtotalBdt = round($subtotalLocal * $rate, 4);
            $coupon = $this->resolveCouponDiscount(
                $data['coupon_code'] ?? null,
                $subtotalLocal,
                $currency,
                $orderedAt,
                $documentType,
            );
            $shippingAmount = round($shippingLocal * $rate, 4);
            $totalLocal = $subtotalLocal + $taxLocal + $shippingLocal - $discountLocal - $coupon['coupon_discount_local'];
            $totalBdt = $subtotalBdt + round($taxLocal * $rate, 4) + $shippingAmount - round($discountLocal * $rate, 4) - $coupon['coupon_discount_amount'];
            $credit = $this->resolveCreditOverride($customer, $totalBdt, $data);

            $createdBy = $this->currentUserId() ?? ($data['created_by'] ?? null);
            $assignment = $this->salesAssignment($data, $customer, $createdBy);

            $so = $this->createWithSequentialNumber($documentType === 'quotation' ? 'QT' : 'SO', SaleOrder::class, 'so_number', [
                'document_type'         => $documentType,
                'warehouse_id'          => $warehouseId,
                'customer_id'           => $data['customer_id'] ?? null,
                'coupon_id'             => $coupon['coupon_id'],
                'price_tier_code'       => $tierCode,
                'coupon_code'           => $coupon['coupon_code'],
                'coupon_name'           => $coupon['coupon_name'],
                'coupon_discount_type'  => $coupon['coupon_discount_type'],
                'coupon_discount_value' => $coupon['coupon_discount_value'],
                'currency'              => $currency,
                'exchange_rate'         => $rate,
                'status'                => SaleOrderStatus::DRAFT,
                'ordered_at'            => $orderedAt,
                'notes'                 => $data['notes'] ?? null,
                'created_by'            => $createdBy,
                ...$assignment,
                'tax_local'                     => $taxLocal,
                'tax_amount'                    => round($taxLocal * $rate, 4),
                'discount_local'                => $discountLocal,
                'discount_amount'               => round($discountLocal * $rate, 4),
                'shipping_local'                => $shippingLocal,
                'shipping_amount'               => $shippingAmount,
                'coupon_discount_local'         => $coupon['coupon_discount_local'],
                'coupon_discount_amount'        => $coupon['coupon_discount_amount'],
                'subtotal_local'                => $subtotalLocal,
                'subtotal_amount'               => $subtotalBdt,
                'total_local'                   => $totalLocal,
                'total_amount'                  => $totalBdt,
                'paid_amount'                   => 0,
                'due_amount'                    => $totalBdt,
                'credit_limit_amount'           => $credit['credit_limit_amount'],
                'credit_exposure_before_amount' => $credit['credit_exposure_before_amount'],
                'credit_exposure_after_amount'  => $credit['credit_exposure_after_amount'],
                'credit_override_required'      => $credit['credit_override_required'],
                'credit_override_approved_by'   => $credit['credit_override_approved_by'],
                'credit_override_approved_at'   => $credit['credit_override_approved_at'],
                'credit_override_notes'         => $credit['credit_override_notes'],
                'cogs_amount'                   => 0,
            ]);

            foreach ($lineItems as $lineItem) {
                SaleOrderItem::create([
                    'sale_order_id'     => $so->id,
                    'product_id'        => $lineItem['product_id'],
                    'variant_id'        => $lineItem['variant_id'],
                    'price_tier_code'   => $lineItem['price_tier_code'],
                    'qty_ordered'       => $lineItem['qty_ordered'],
                    'qty_fulfilled'     => $lineItem['qty_fulfilled'],
                    'from_damaged'      => $lineItem['from_damaged'],
                    'unit_price_local'  => $lineItem['unit_price_local'],
                    'unit_price_amount' => $lineItem['unit_price_amount'],
                    'unit_cost_amount'  => $lineItem['unit_cost_amount'],
                    'discount_pct'      => $lineItem['discount_pct'],
                    'line_total_local'  => $lineItem['line_total_local'],
                    'line_total_amount' => $lineItem['line_total_amount'],
                    'notes'             => $lineItem['notes'],
                ]);
            }

            // order_role/agent_customer_id/paired_sale_order_id belong to the optional
            // laravel-inventory-pro add-on (not in this model's $fillable), so an explicit
            // value is written directly rather than through mass assignment.
            if (!empty($data['order_role']) && Schema::hasColumn($so->getTable(), 'order_role')) {
                DB::table($so->getTable())->where('id', $so->id)->update(['order_role' => $data['order_role']]);
            }

            return $so->fresh(['customer', 'warehouse', 'items.product']);
        });

        // Queued rather than called inline: syncSaleOrderDocument() talks to the accounting
        // package (invoice create/save, GL account lookups) and can throw (e.g. a
        // missing/inactive chart-of-accounts entry) or simply run slow. Called inline right
        // after the transaction above, either of those turned an already-committed sale order
        // into a 500/timeout response to the browser — the user saw a failure and often
        // retried, creating a real duplicate order, while the original sat in the database the
        // whole time. Running it as a job means nothing this sync does can affect this
        // response at all.
        SyncSaleOrderAccountingDocumentJob::dispatch($so->id);

        // $so is already the post-commit state from the fresh() call above (with 'warehouse'
        // now included so callers don't lazy-load it per order) — a trailing ->refresh() here
        // would just re-run the same customer/items.product queries a second time for
        // identical data, doubling every sale order creation's round trips against the DB.
        return $so;
    }

    public function createSaleOrderFromQuotation(int $quotationId, array $overrides = []): SaleOrder
    {
        $quotation = SaleOrder::query()
            ->with(['items'])
            ->where('document_type', 'quotation')
            ->findOrFail($quotationId);

        if ($quotation->status === SaleOrderStatus::CANCELLED) {
            throw new InvalidTransitionException("Quotation #{$quotation->so_number} has been cancelled and cannot be converted.");
        }

        $metadata = $this->documentMetadata($quotation);
        $convertedSaleOrderId = (int) ($metadata['converted_sale_order_id'] ?? 0);

        if ($convertedSaleOrderId > 0) {
            $existingSaleOrder = SaleOrder::query()
                ->where('document_type', 'order')
                ->find($convertedSaleOrderId);

            if ($existingSaleOrder) {
                return $existingSaleOrder->fresh(['customer', 'items.product']);
            }
        }

        $saleOrder = $this->createSaleOrder([
            'warehouse_id'    => (int) ($overrides['warehouse_id'] ?? $quotation->warehouse_id),
            'customer_id'     => $overrides['customer_id'] ?? $quotation->customer_id,
            'price_tier_code' => (string) ($overrides['price_tier_code'] ?? $quotation->price_tier_code),
            'coupon_code'     => $overrides['coupon_code'] ?? $quotation->coupon_code,
            'currency'        => (string) ($overrides['currency'] ?? $quotation->currency),
            'exchange_rate'   => (float) ($overrides['exchange_rate'] ?? $quotation->exchange_rate),
            'document_type'   => 'order',
            'ordered_at'      => $overrides['ordered_at'] ?? now(),
            'notes'           => $overrides['notes'] ?? $this->appendConversionNote($quotation->notes, "Converted from quotation {$quotation->so_number}."),
            'created_by'      => $overrides['created_by'] ?? $quotation->created_by,
            'tax_local'       => (float) ($overrides['tax_local'] ?? $quotation->tax_local),
            'discount_local'  => (float) ($overrides['discount_local'] ?? $quotation->discount_local),
            'shipping_local'  => (float) ($overrides['shipping_local'] ?? $quotation->shipping_local),
            'items'           => collect($overrides['items'] ?? $quotation->items)
                ->map(function ($item): array {
                    return [
                        'product_id'       => (int) $item['product_id'],
                        'variant_id'       => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                        'price_tier_code'  => (string) $item['price_tier_code'],
                        'qty_ordered'      => (float) $item['qty_ordered'],
                        'unit_price_local' => (float) $item['unit_price_local'],
                        'discount_pct'     => (float) $item['discount_pct'],
                        'notes'            => $item['notes'] ?? null,
                    ];
                })
                ->values()
                ->all(),
        ]);

        $this->putDocumentMetadata($quotation, array_merge($metadata, [
            'converted_sale_order_id'     => $saleOrder->getKey(),
            'converted_sale_order_number' => $saleOrder->so_number,
            'converted_sale_order_at'     => now()->toIso8601String(),
        ]));

        $this->putDocumentMetadata($saleOrder, array_merge($this->documentMetadata($saleOrder), [
            'source_quotation_id'     => $quotation->getKey(),
            'source_quotation_number' => $quotation->so_number,
        ]));

        return $saleOrder;
    }

    public function resolveCouponDiscount(?string $couponCode, float $subtotalLocal, string $currency, DateTimeInterface|string|null $orderedAt = null, ?string $documentType = 'order', ?int $ignoreSaleOrderId = null): array
    {
        $normalizedCode = $this->normalizeCouponCode($couponCode);

        if ($normalizedCode === null) {
            return [
                'coupon'                 => null,
                'coupon_id'              => null,
                'coupon_code'            => null,
                'coupon_name'            => null,
                'coupon_discount_type'   => null,
                'coupon_discount_value'  => 0.0,
                'coupon_discount_local'  => 0.0,
                'coupon_discount_amount' => 0.0,
            ];
        }

        $orderedAt = $orderedAt instanceof DateTimeInterface
            ? Carbon::parse($orderedAt->format('Y-m-d H:i:s'))
            : Carbon::parse($orderedAt ?? now());

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->first();

        if (!$coupon || !$coupon->is_active) {
            throw ValidationException::withMessages([
                'coupon_code' => 'The selected coupon code is not valid.',
            ]);
        }

        if ($coupon->starts_at && $coupon->starts_at->gt($orderedAt)) {
            throw ValidationException::withMessages([
                'coupon_code' => 'This coupon is not active yet.',
            ]);
        }

        if ($coupon->ends_at && $coupon->ends_at->lt($orderedAt)) {
            throw ValidationException::withMessages([
                'coupon_code' => 'This coupon has expired.',
            ]);
        }

        $subtotalAmount = round($this->convertToBase($subtotalLocal, $currency, $orderedAt->toDateString()), 4);
        $minimumSubtotalAmount = (float) ($coupon->minimum_subtotal_amount ?? 0);

        if ($minimumSubtotalAmount > 0 && $subtotalAmount < $minimumSubtotalAmount) {
            throw ValidationException::withMessages([
                'coupon_code' => 'This coupon requires a higher order subtotal.',
            ]);
        }

        if ($coupon->usage_limit !== null && $documentType !== 'quotation') {
            $usageCount = SaleOrder::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('document_type', '!=', 'quotation')
                ->when($ignoreSaleOrderId !== null, fn ($query) => $query->whereKeyNot($ignoreSaleOrderId))
                ->count();

            if ($usageCount >= $coupon->usage_limit) {
                throw ValidationException::withMessages([
                    'coupon_code' => 'This coupon has reached its usage limit.',
                ]);
            }
        }

        $discountAmount = match ($coupon->discount_type) {
            'percent' => round($subtotalAmount * ((float) $coupon->discount_value / 100), 4),
            'fixed'   => round((float) $coupon->discount_value, 4),
            default   => throw new \InvalidArgumentException("Unknown coupon discount type [{$coupon->discount_type}]."),
        };

        $maximumDiscountAmount = (float) ($coupon->maximum_discount_amount ?? 0);

        if ($maximumDiscountAmount > 0) {
            $discountAmount = min($discountAmount, $maximumDiscountAmount);
        }

        $discountAmount = min($discountAmount, $subtotalAmount);
        $discountLocal = round($this->convertFromBase($discountAmount, $currency, $orderedAt->toDateString()), 4);

        return [
            'coupon'                 => $coupon,
            'coupon_id'              => $coupon->getKey(),
            'coupon_code'            => $coupon->code,
            'coupon_name'            => $coupon->name,
            'coupon_discount_type'   => $coupon->discount_type,
            'coupon_discount_value'  => round((float) $coupon->discount_value, 4),
            'coupon_discount_local'  => $discountLocal,
            'coupon_discount_amount' => round($discountAmount, 4),
        ];
    }

    public function normalizeCouponCode(?string $couponCode): ?string
    {
        $couponCode = strtoupper(trim((string) $couponCode));

        return $couponCode !== '' ? $couponCode : null;
    }

    private function normalizePriceTierCode(string $tierCode): string
    {
        $tier = PriceTierCode::tryFrom($tierCode);

        if (!$tier) {
            throw new \InvalidArgumentException("Unknown price tier [{$tierCode}].");
        }

        return $tier->value;
    }
}
