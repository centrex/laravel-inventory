<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        // qty_received already tracks good units; damaged and lost are additional qty at receipt
        Schema::connection($c)->table($p . 'stock_receipt_items', function (Blueprint $table): void {
            $table->decimal('qty_damaged', 18, 4)->default(0)->after('qty_received');
            $table->decimal('qty_lost', 18, 4)->default(0)->after('qty_damaged');
        });

        // Dedicated damage bin location per warehouse (virtual bin, not a physical warehouse)
        // Damaged qty is tracked on warehouse_products via a separate column added in Feature 3.
        // Here we only add the incoming damage columns to receipt items.
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->table($p . 'stock_receipt_items', function (Blueprint $table): void {
            $table->dropColumn(['qty_damaged', 'qty_lost']);
        });
    }
};
