<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales agent support: paired B2C (customer, retail) / B2B (agent, wholesale) sale orders.
 * The B2B order controls stock reservation/fulfillment and drives company cost; the B2C
 * order is the customer-facing reference. OrderRole::AGENT_B2C/AGENT_B2B already exist on
 * the base enum — this migration adds the columns/tables that give them somewhere to live.
 */
return new class extends Migration
{
    private function prefix(): string
    {
        return config('inventory.table_prefix', 'inv_') ?: 'inv_';
    }

    public function up(): void
    {
        $prefix = $this->prefix();
        $saleOrdersTable = $prefix . 'sale_orders';
        $customersTable = $prefix . 'customers';
        $agentsTable = $prefix . 'agents';
        $agentCustomersTable = $prefix . 'agent_customers';

        if (Schema::hasTable($saleOrdersTable)) {
            Schema::table($saleOrdersTable, function (Blueprint $table) use ($saleOrdersTable): void {
                if (!Schema::hasColumn($saleOrdersTable, 'order_role')) {
                    // 'direct' | 'agent_b2c' | 'agent_b2b' — see OrderRole enum
                    $table->string('order_role', 20)->default('direct')->after('accounting_invoice_id');
                }

                if (!Schema::hasColumn($saleOrdersTable, 'paired_sale_order_id')) {
                    $table->unsignedBigInteger('paired_sale_order_id')
                        ->nullable()
                        ->after('order_role')
                        ->comment('For agent orders: links the B2C and B2B pair together');
                }

                if (!Schema::hasColumn($saleOrdersTable, 'agent_customer_id')) {
                    $table->unsignedBigInteger('agent_customer_id')
                        ->nullable()
                        ->after('paired_sale_order_id')
                        ->comment('The inv_customer record of the agent (set on both sides of the pair)');
                }
            });

            Schema::table($saleOrdersTable, function (Blueprint $table) use ($saleOrdersTable): void {
                $indexes = collect(Schema::getIndexes($saleOrdersTable))->pluck('name');

                if (!$indexes->contains($saleOrdersTable . '_order_role_index')) {
                    $table->index('order_role');
                }

                if (!$indexes->contains($saleOrdersTable . '_paired_sale_order_id_index')) {
                    $table->index('paired_sale_order_id');
                }
            });
        }

        if (!Schema::hasTable($agentsTable)) {
            Schema::create($agentsTable, function (Blueprint $table): void {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('name', 300);
                $table->string('email', 200)->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('zone', 120)->nullable();
                $table->string('area', 120)->nullable();
                $table->string('price_tier_code', 30)->nullable()->default('b2b_wholesale');
                $table->decimal('commission_rate_pct', 8, 4)->default(0);
                // Links to the agent's own inv_customers record (used as customer on B2B sale orders)
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable($customersTable)) {
            Schema::table($customersTable, function (Blueprint $table) use ($customersTable): void {
                if (!Schema::hasColumn($customersTable, 'is_agent')) {
                    $table->boolean('is_agent')->default(false)->after('is_active');
                }

                if (!Schema::hasColumn($customersTable, 'agent_id')) {
                    $table->unsignedBigInteger('agent_id')->nullable()->after('is_agent');
                }
            });
        }

        if (!Schema::hasTable($agentCustomersTable)) {
            Schema::create($agentCustomersTable, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('agent_id');
                $table->unsignedBigInteger('customer_id');
                $table->string('territory', 120)->nullable();
                $table->date('assigned_at')->nullable();
                $table->boolean('is_primary')->default(true); // primary agent for this customer
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['agent_id', 'customer_id']);
            });
        }
    }

    public function down(): void
    {
        $prefix = $this->prefix();
        $saleOrdersTable = $prefix . 'sale_orders';
        $customersTable = $prefix . 'customers';

        Schema::dropIfExists($prefix . 'agent_customers');
        Schema::dropIfExists($prefix . 'agents');

        if (Schema::hasTable($customersTable)) {
            Schema::table($customersTable, function (Blueprint $table) use ($customersTable): void {
                $toDrop = array_filter([
                    Schema::hasColumn($customersTable, 'agent_id') ? 'agent_id' : null,
                    Schema::hasColumn($customersTable, 'is_agent') ? 'is_agent' : null,
                ]);

                if ($toDrop !== []) {
                    $table->dropColumn(array_values($toDrop));
                }
            });
        }

        if (Schema::hasTable($saleOrdersTable)) {
            Schema::table($saleOrdersTable, function (Blueprint $table) use ($saleOrdersTable): void {
                $toDrop = array_filter([
                    Schema::hasColumn($saleOrdersTable, 'agent_customer_id') ? 'agent_customer_id' : null,
                    Schema::hasColumn($saleOrdersTable, 'paired_sale_order_id') ? 'paired_sale_order_id' : null,
                    Schema::hasColumn($saleOrdersTable, 'order_role') ? 'order_role' : null,
                ]);

                if ($toDrop !== []) {
                    $table->dropColumn(array_values($toDrop));
                }
            });
        }
    }
};
