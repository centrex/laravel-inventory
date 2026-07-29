<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $shipmentsTable = $prefix . 'shipments';
        $shipmentItemsTable = $prefix . 'shipment_items';
        $shipmentBoxItemsTable = $prefix . 'shipment_box_items';

        if (!$schema->hasColumn($shipmentsTable, 'customs_amount')) {
            $schema->table($shipmentsTable, function (Blueprint $table): void {
                $table->decimal('customs_amount', 18, 4)->default(0)->after('shipping_cost_amount');
                $table->decimal('handling_amount', 18, 4)->default(0)->after('customs_amount');
                $table->decimal('insurance_amount', 18, 4)->default(0)->after('handling_amount');
            });
        }

        if (!$schema->hasColumn($shipmentItemsTable, 'extra_charges_allocated_amount')) {
            $schema->table($shipmentItemsTable, function (Blueprint $table): void {
                $table->decimal('extra_charges_allocated_amount', 18, 4)->default(0)->after('shipping_allocated_amount');
            });
        }

        if (!$schema->hasColumn($shipmentBoxItemsTable, 'extra_charges_allocated_amount')) {
            $schema->table($shipmentBoxItemsTable, function (Blueprint $table): void {
                $table->decimal('extra_charges_allocated_amount', 18, 4)->default(0)->after('shipping_allocated_amount');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'shipments', function (Blueprint $table): void {
            $table->dropColumn(['customs_amount', 'handling_amount', 'insurance_amount']);
        });

        $schema->table($prefix . 'shipment_items', function (Blueprint $table): void {
            $table->dropColumn('extra_charges_allocated_amount');
        });

        $schema->table($prefix . 'shipment_box_items', function (Blueprint $table): void {
            $table->dropColumn('extra_charges_allocated_amount');
        });
    }
};
