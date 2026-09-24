<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

/**
 * Central service class for all inventory operations.
 *
 * This is the class behind the {@see Facades\Inventory} facade. Organised as one
 * trait per domain area (see `Concerns\`), each still named after the same logical
 * sections the class used to be internally divided into by comment banner:
 *
 *   Exchange Rates      – set / get / convert currency rates
 *   Pricing             – set, resolve, and list product prices per tier
 *   Stock Ledger        – read on-hand, reserved, and in-transit quantities
 *   Shared Helpers       – WAC recalculation, movement ledger, sequential numbering, locking
 *   Purchase Orders     – create, submit, confirm, receive, cancel
 *   Stock Receipts      – create / post / void goods-received notes (GRN)
 *   Sale Orders         – create, confirm, reserve, fulfil, cancel, quotation convert
 *   Returns             – customer returns (SO) and supplier returns (PO)
 *   Transfers           – inter-warehouse stock moves
 *   Adjustments         – cycle-count / write-off stock corrections
 *   Pick-Pack-Ship      – pick lists and customer-facing shipments
 *   Inter-Warehouse Shipments – box-tracked, stock-moving shipments between warehouses
 *   Reporting           – stock valuation, movement history, aging
 *   Entity Queries      – products, variants, lots, customers, sale orders
 *   Sales Forecast      – the heaviest computation: demand/cash-flow projection
 *   Inventory Helpers   – cross-cutting: transitions, credit overrides, document metadata
 *   Partner Management  – API partners (dropshippers, e-commerce, marketplaces)
 *   Product Trend & Profitability Analytics
 */
class Inventory
{
    use Concerns\GeneratesInventoryReports;
    use Concerns\GeneratesProductTrendAnalytics;
    use Concerns\GeneratesSalesForecast;
    use Concerns\HasInventoryHelpers;
    use Concerns\HasSharedInventoryHelpers;
    use Concerns\ManagesAdjustments;
    use Concerns\ManagesExchangeRates;
    use Concerns\ManagesInterWarehouseShipments;
    use Concerns\ManagesPartners;
    use Concerns\ManagesPickPackShip;
    use Concerns\ManagesPricing;
    use Concerns\ManagesPurchaseOrders;
    use Concerns\ManagesReturns;
    use Concerns\ManagesSaleOrderLifecycle;
    use Concerns\ManagesSaleOrders;
    use Concerns\ManagesStockLedger;
    use Concerns\ManagesStockReceipts;
    use Concerns\ManagesTransfers;
    use Concerns\QueriesInventoryEntities;
}
