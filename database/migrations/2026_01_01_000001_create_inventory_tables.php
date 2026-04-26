<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));
        $withUserForeignKeys = (bool) config('inventory.user_foreign_keys', false);

        // ── Warehouses ────────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->char('country_code', 2)->index();
            $table->char('currency', 3);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false)->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Product Categories ────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'product_categories', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained($p . 'product_categories')->nullOnDelete();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Product Brands ────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'product_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Products ──────────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'products', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained($p . 'product_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained($p . 'product_brands')->nullOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('name', 300);
            $table->text('description')->nullable();
            $table->string('unit', 30)->default('pcs');
            $table->decimal('weight_kg', 10, 4)->nullable();
            $table->string('barcode', 100)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_stockable')->default(true);
            $table->text('variant_names')->nullable(); // JSON object of variant dimension key→value pairs, e.g. {"size":"Large","color":"Red"}
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Product Variant Attribute Types ───────────────────────────────────
        // Defines variation axes (Color, Size, Material, etc.) for a product family.
        Schema::connection($c)->create($p . 'product_variant_attribute_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ── Product Variant Attribute Values ──────────────────────────────────
        // Valid values per type (e.g. Color → Red, Blue; Size → S, M, L, XL).
        Schema::connection($c)->create($p . 'product_variant_attribute_values', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('attribute_type_id')
                ->constrained($p . 'product_variant_attribute_types')
                ->cascadeOnDelete();
            $table->string('value', 150);
            $table->string('display_value', 150)->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_type_id', 'value'], $p . 'pvav_type_value_unique');
            $table->index('attribute_type_id');
        });

        // ── Product Variants ──────────────────────────────────────────────────
        // One row per sellable SKU variant of a product (e.g. Red / L, Blue / M).
        // Structured attributes stored as JSON object keyed by attribute-type slug:
        //   {"color":"red","size":"l"}
        // The normalised pivot below links variants to attribute-value rows for UI.
        Schema::connection($c)->create($p . 'product_variants', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained($p . 'products')
                ->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('name', 300);
            $table->string('barcode', 100)->nullable()->unique();
            $table->decimal('weight_kg', 10, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->json('attributes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'is_active'], $p . 'product_variants_product_active_idx');
            $table->index('product_id');
        });

        // ── Variant ↔ Attribute-Value pivot ───────────────────────────────────
        // Normalised link between a variant and its per-axis attribute values.
        Schema::connection($c)->create($p . 'product_variant_attributes', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('variant_id')
                ->constrained($p . 'product_variants')
                ->cascadeOnDelete();
            $table->foreignId('attribute_type_id')
                ->constrained($p . 'product_variant_attribute_types')
                ->cascadeOnDelete();
            $table->foreignId('attribute_value_id')
                ->constrained($p . 'product_variant_attribute_values')
                ->cascadeOnDelete();

            $table->unique(
                ['variant_id', 'attribute_type_id'],
                $p . 'product_variant_attributes_unique',
            );
            $table->index('variant_id');
        });

        // ── Suppliers ─────────────────────────────────────────────────────────
        // geo JSON: {zone, area, segment, ...} replaces separate zone/area/demographic columns
        Schema::connection($c)->create($p . 'suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 300);
            $table->char('country_code', 2)->nullable();
            $table->json('geo')->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->string('contact_name', 200)->nullable();
            $table->string('contact_email', 200)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('purchase_manager_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_assistant_manager_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_executive_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->string('modelable_type')->nullable();
            $table->unsignedBigInteger('modelable_id')->nullable();
            $table->unsignedBigInteger('accounting_vendor_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['modelable_type', 'modelable_id']);
            $table->index('accounting_vendor_id');
        });

        // ── Customers ─────────────────────────────────────────────────────────
        // geo JSON: {zone, area, segment, ...} replaces separate zone/area/demographic columns
        Schema::connection($c)->create($p . 'customers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('organization_name', 300)->nullable()->index();
            $table->string('name', 300);
            $table->string('email', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->json('geo')->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->decimal('credit_limit_amount', 18, 4)->default(0);
            $table->string('price_tier_code', 30)->nullable()->index();
            $table->unsignedBigInteger('sales_owner_id')->nullable()->index();
            $table->string('sales_owner_designation', 40)->nullable()->index();
            $table->unsignedBigInteger('sales_manager_id')->nullable()->index();
            $table->unsignedBigInteger('sales_assistant_manager_id')->nullable()->index();
            $table->unsignedBigInteger('sales_executive_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->nullableMorphs('modelable');
            $table->unsignedBigInteger('accounting_customer_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('accounting_customer_id');
        });

        // ── Coupons ───────────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 18, 4);
            $table->decimal('minimum_subtotal_amount', 18, 4)->nullable();
            $table->decimal('maximum_discount_amount', 18, 4)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        // ── Commercial Team Members ───────────────────────────────────────────
        Schema::connection($c)->create($p . 'commercial_team_members', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow', 20)->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('manager_user_id')->nullable()->index();
            $table->string('role', 40)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['workflow', 'user_id']);
        });

        // ── Product Prices ────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'product_prices', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('product_id')->constrained($p . 'products')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->string('price_tier_code', 30)->index();
            $table->foreignId('warehouse_id')->nullable()->constrained($p . 'warehouses')->cascadeOnDelete();
            $table->decimal('price_amount', 18, 4);
            $table->decimal('cost_price', 18, 4)->nullable();
            $table->unsignedInteger('moq')->default(1);
            $table->unsignedInteger('preorder_moq')->nullable();
            $table->decimal('price_local', 18, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'price_tier_code']);
            $table->index('warehouse_id');
            $table->index(['product_id', 'variant_id', 'price_tier_code'], $p . 'product_prices_variant_lookup_idx');
            $table->index(['product_id', 'price_tier_code', 'warehouse_id', 'is_active'], $p . 'product_prices_lookup_idx');
        });

        // ── Warehouse-Product Stock Ledger ────────────────────────────────────
        // variant_id=NULL means base product (no variant); unique on (warehouse_id, product_id, variant_id)
        Schema::connection($c)->create($p . 'warehouse_products', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('qty_on_hand', 18, 4)->default(0);
            $table->decimal('qty_reserved', 18, 4)->default(0);
            $table->decimal('qty_in_transit', 18, 4)->default(0);
            $table->decimal('wac_amount', 18, 4)->default(0);
            $table->decimal('reorder_point', 18, 4)->nullable();
            $table->decimal('reorder_qty', 18, 4)->nullable();
            $table->string('bin_location', 100)->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id', 'variant_id'], $p . 'warehouse_products_variant_lookup_idx');
            $table->index('warehouse_id', $p . 'warehouse_products_warehouse_fk_idx');
            $table->index('product_id', $p . 'warehouse_products_product_fk_idx');
        });

        // ── Purchase Orders ───────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'purchase_orders', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('po_number', 50)->unique();
            $table->string('document_type', 30)->default('order')->index();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained($p . 'suppliers')->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 8);
            $table->decimal('subtotal_local', 18, 4)->default(0);
            $table->decimal('subtotal_amount', 18, 4)->default(0);
            $table->decimal('tax_local', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('discount_local', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('shipping_local', 18, 4)->default(0);
            $table->decimal('shipping_amount', 18, 4)->default(0);
            $table->decimal('other_charges_amount', 18, 4)->default(0);
            $table->decimal('total_local', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->string('status', 30)->default('draft');
            $table->timestamp('ordered_at')->nullable();
            $table->date('expected_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('purchase_manager_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_assistant_manager_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_executive_id')->nullable()->index();
            $table->unsignedBigInteger('accounting_bill_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
            $table->index('supplier_id');
            $table->index('status');
            $table->index('created_by');
            $table->index('accounting_bill_id');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // ── Purchase Order Items ──────────────────────────────────────────────
        Schema::connection($c)->create($p . 'purchase_order_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained($p . 'purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('qty_ordered', 18, 4);
            $table->decimal('qty_received', 18, 4)->default(0);
            $table->decimal('unit_price_local', 18, 4);
            $table->decimal('unit_price_amount', 18, 4);
            $table->decimal('line_total_local', 18, 4)->default(0);
            $table->decimal('line_total_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index(['product_id', 'variant_id'], $p . 'purchase_order_items_product_variant_idx');
        });

        // ── Stock Receipts (GRN) ──────────────────────────────────────────────
        Schema::connection($c)->create($p . 'stock_receipts', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('grn_number', 50)->unique();
            $table->foreignId('purchase_order_id')->nullable()->constrained($p . 'purchase_orders')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('accounting_journal_entry_id')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index(['warehouse_id', 'status']);
            $table->index('created_by');
            $table->index('accounting_journal_entry_id');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // ── Stock Receipt Items ───────────────────────────────────────────────
        Schema::connection($c)->create($p . 'stock_receipt_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('stock_receipt_id')->constrained($p . 'stock_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained($p . 'purchase_order_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('qty_received', 18, 4);
            $table->decimal('unit_cost_local', 18, 4);
            $table->decimal('unit_cost_amount', 18, 4);
            $table->decimal('exchange_rate', 18, 8);
            $table->decimal('wac_before_amount', 18, 4)->default(0);
            $table->decimal('wac_after_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('stock_receipt_id');
            $table->index(['product_id', 'variant_id'], $p . 'stock_receipt_items_product_variant_idx');
        });

        // ── Sale Orders ───────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'sale_orders', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('so_number', 50)->unique();
            $table->string('document_type', 30)->default('order')->index();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained($p . 'customers')->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained($p . 'coupons')->nullOnDelete();
            $table->string('price_tier_code', 30)->index();
            $table->string('coupon_code', 50)->nullable()->index();
            $table->string('coupon_name', 150)->nullable();
            $table->string('coupon_discount_type', 20)->nullable();
            $table->decimal('coupon_discount_value', 18, 4)->default(0);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 8);
            $table->decimal('subtotal_local', 18, 4)->default(0);
            $table->decimal('subtotal_amount', 18, 4)->default(0);
            $table->decimal('tax_local', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('discount_local', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('shipping_local', 18, 4)->default(0);
            $table->decimal('shipping_amount', 18, 4)->default(0);
            $table->decimal('coupon_discount_local', 18, 4)->default(0);
            $table->decimal('coupon_discount_amount', 18, 4)->default(0);
            $table->decimal('total_local', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->decimal('credit_limit_amount', 18, 4)->default(0);
            $table->decimal('credit_exposure_before_amount', 18, 4)->default(0);
            $table->decimal('credit_exposure_after_amount', 18, 4)->default(0);
            $table->boolean('credit_override_required')->default(false);
            $table->unsignedBigInteger('credit_override_approved_by')->nullable();
            $table->timestamp('credit_override_approved_at')->nullable();
            $table->text('credit_override_notes')->nullable();
            $table->decimal('cogs_amount', 18, 4)->default(0);
            $table->string('status', 30)->default('draft');
            $table->timestamp('ordered_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('sales_manager_id')->nullable()->index();
            $table->unsignedBigInteger('sales_assistant_manager_id')->nullable()->index();
            $table->unsignedBigInteger('sales_executive_id')->nullable()->index();
            $table->unsignedBigInteger('accounting_invoice_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
            $table->index('customer_id');
            $table->index('status');
            $table->index('created_by');
            $table->index('coupon_id');
            $table->index('credit_override_required');
            $table->index('credit_override_approved_by');
            $table->index('accounting_invoice_id');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // ── Sale Order Items ──────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'sale_order_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('sale_order_id')->constrained($p . 'sale_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->string('price_tier_code', 30)->nullable()->index();
            $table->decimal('qty_ordered', 18, 4);
            $table->decimal('qty_fulfilled', 18, 4)->default(0);
            $table->decimal('unit_price_local', 18, 4);
            $table->decimal('unit_price_amount', 18, 4);
            $table->decimal('unit_cost_amount', 18, 4)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('line_total_local', 18, 4)->default(0);
            $table->decimal('line_total_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('sale_order_id');
            $table->index(['product_id', 'variant_id'], $p . 'sale_order_items_product_variant_idx');
        });

        // ── Inter-Warehouse Transfers ─────────────────────────────────────────
        Schema::connection($c)->create($p . 'transfers', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('transfer_number', 50)->unique();
            $table->foreignId('from_warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->decimal('total_weight_kg', 18, 4)->default(0);
            $table->decimal('shipping_rate_per_kg', 18, 4)->default(0);
            $table->decimal('shipping_cost_amount', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['from_warehouse_id', 'status']);
            $table->index(['to_warehouse_id', 'status']);
            $table->index('status');
            $table->index('created_by');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // ── Transfer Items ────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'transfer_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained($p . 'transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('qty_sent', 18, 4);
            $table->decimal('qty_received', 18, 4)->default(0);
            $table->decimal('unit_cost_source_amount', 18, 4);
            $table->decimal('weight_kg_total', 18, 4)->default(0);
            $table->decimal('shipping_allocated_amount', 18, 4)->default(0);
            $table->decimal('unit_landed_cost_amount', 18, 4)->default(0);
            $table->decimal('wac_source_before_amount', 18, 4)->default(0);
            $table->decimal('wac_dest_before_amount', 18, 4)->default(0);
            $table->decimal('wac_dest_after_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('transfer_id');
            $table->index(['product_id', 'variant_id'], $p . 'transfer_items_product_variant_idx');
        });

        // ── Transfer Boxes ────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'transfer_boxes', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained($p . 'transfers')->cascadeOnDelete();
            $table->string('box_code', 50)->nullable();
            $table->decimal('measured_weight_kg', 18, 4);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('transfer_id');
        });

        // ── Transfer Box Items ────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'transfer_box_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('transfer_box_id')->constrained($p . 'transfer_boxes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('qty_sent', 18, 4);
            $table->decimal('theoretical_weight_kg', 18, 4)->default(0);
            $table->decimal('allocated_weight_kg', 18, 4)->default(0);
            $table->decimal('weight_ratio', 18, 8)->default(0);
            $table->decimal('source_unit_cost_amount', 18, 4)->default(0);
            $table->decimal('shipping_allocated_amount', 18, 4)->default(0);
            $table->decimal('unit_landed_cost_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('transfer_box_id');
            $table->index(['product_id', 'variant_id'], $p . 'transfer_box_items_product_variant_idx');
        });

        // ── Sale Returns ──────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'sale_returns', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('return_number', 50)->unique();
            $table->foreignId('sale_order_id')->nullable()->constrained($p . 'sale_orders')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained($p . 'customers')->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
            $table->index('customer_id');
            $table->index('sale_order_id');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // ── Sale Return Items ─────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'sale_return_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('sale_return_id')->constrained($p . 'sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_order_item_id')->nullable()->constrained($p . 'sale_order_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('qty_returned', 18, 4);
            $table->decimal('unit_price_amount', 18, 4)->default(0);
            $table->decimal('unit_cost_amount', 18, 4)->default(0);
            $table->decimal('line_total_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variant_id'], $p . 'sale_return_items_product_variant_idx');
        });

        // ── Purchase Returns ──────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'purchase_returns', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('return_number', 50)->unique();
            $table->foreignId('purchase_order_id')->nullable()->constrained($p . 'purchase_orders')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained($p . 'suppliers')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
            $table->index('supplier_id');
            $table->index('purchase_order_id');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // ── Purchase Return Items ─────────────────────────────────────────────
        Schema::connection($c)->create($p . 'purchase_return_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained($p . 'purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained($p . 'purchase_order_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('qty_returned', 18, 4);
            $table->decimal('unit_cost_amount', 18, 4)->default(0);
            $table->decimal('line_total_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variant_id'], $p . 'purchase_return_items_product_variant_idx');
        });

        // ── Adjustments ───────────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'adjustments', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('adjustment_number', 50)->unique();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->string('reason', 30);
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('adjusted_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('accounting_journal_entry_id')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
            $table->index('created_by');
            $table->index('accounting_journal_entry_id');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // ── Adjustment Items ──────────────────────────────────────────────────
        Schema::connection($c)->create($p . 'adjustment_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('adjustment_id')->constrained($p . 'adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('qty_system', 18, 4);
            $table->decimal('qty_actual', 18, 4);
            $table->decimal('qty_delta', 18, 4);
            $table->decimal('unit_cost_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('adjustment_id');
            $table->index(['product_id', 'variant_id'], $p . 'adjustment_items_product_variant_idx');
        });

        // ── Stock Movements (append-only audit log) ───────────────────────────
        Schema::connection($c)->create($p . 'stock_movements', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->string('movement_type', 30);
            $table->enum('direction', ['in', 'out']);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_before', 18, 4);
            $table->decimal('qty_after', 18, 4);
            $table->decimal('unit_cost_amount', 18, 4)->nullable();
            $table->decimal('wac_amount', 18, 4)->nullable();
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('moved_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['warehouse_id', 'product_id', 'variant_id'], $p . 'stock_movements_product_variant_idx');
            $table->index('movement_type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('moved_at');
            $table->index(['product_id', 'moved_at']);
            $table->index('created_by');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));

        $tables = [
            'stock_movements', 'adjustment_items', 'adjustments',
            'purchase_return_items', 'purchase_returns',
            'sale_return_items', 'sale_returns',
            'transfer_box_items', 'transfer_boxes', 'transfer_items', 'transfers',
            'sale_order_items', 'sale_orders',
            'stock_receipt_items', 'stock_receipts',
            'purchase_order_items', 'purchase_orders',
            'warehouse_products', 'product_prices',
            'commercial_team_members', 'coupons',
            'customers', 'suppliers',
            'product_variant_attributes',
            'product_variants',
            'product_variant_attribute_values',
            'product_variant_attribute_types',
            'products', 'product_brands', 'product_categories', 'warehouses',
        ];

        foreach ($tables as $table) {
            Schema::connection($c)->dropIfExists($p . $table);
        }
    }
};
