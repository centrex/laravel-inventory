<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Centrex\Inventory\Models\Partner;
use Centrex\Inventory\Services\{PartnerService, ProductTrendAnalyticsService};
use Illuminate\Support\Collection;

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
 *
 * Two sections are composed services rather than traits — Partner Management
 * ({@see PartnerService}) and Product Trend & Profitability Analytics
 * ({@see ProductTrendAnalyticsService}) — since neither depends on the shared
 * helpers/locking machinery the other sections share, and this class just forwards to them.
 */
class Inventory
{
    use Concerns\GeneratesInventoryReports;
    use Concerns\GeneratesSalesForecast;
    use Concerns\HasInventoryHelpers;
    use Concerns\HasSharedInventoryHelpers;
    use Concerns\ManagesAdjustments;
    use Concerns\ManagesExchangeRates;
    use Concerns\ManagesInterWarehouseShipments;
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

    public function __construct(
        private readonly PartnerService $partners = new PartnerService,
        private readonly ProductTrendAnalyticsService $productTrendAnalytics = new ProductTrendAnalyticsService,
    ) {}

    /** @see PartnerService::createPartner() */
    public function createPartner(array $data): Partner
    {
        return $this->partners->createPartner($data);
    }

    /** @see PartnerService::updatePartner() */
    public function updatePartner(int $partnerId, array $data): Partner
    {
        return $this->partners->updatePartner($partnerId, $data);
    }

    /** @see PartnerService::rotatePartnerApiKey() */
    public function rotatePartnerApiKey(int $partnerId): Partner
    {
        return $this->partners->rotatePartnerApiKey($partnerId);
    }

    /** @see PartnerService::listPartners() */
    public function listPartners(bool $activeOnly = true): Collection
    {
        return $this->partners->listPartners($activeOnly);
    }

    /** @see ProductTrendAnalyticsService::productTrends() */
    public function productTrends(
        int $productId,
        string $period = 'daily',
        int $days = 30,
        ?int $warehouseId = null,
    ): array {
        return $this->productTrendAnalytics->productTrends($productId, $period, $days, $warehouseId);
    }

    /** @see ProductTrendAnalyticsService::customerProductStats() */
    public function customerProductStats(int $customerId): array
    {
        return $this->productTrendAnalytics->customerProductStats($customerId);
    }

    /** @see ProductTrendAnalyticsService::supplierProductStats() */
    public function supplierProductStats(int $supplierId): array
    {
        return $this->productTrendAnalytics->supplierProductStats($supplierId);
    }

    /** @see ProductTrendAnalyticsService::profitabilityReport() */
    public function profitabilityReport(
        string $from,
        string $to,
        string $groupBy = 'product',
        ?int $warehouseId = null,
    ): array {
        return $this->productTrendAnalytics->profitabilityReport($from, $to, $groupBy, $warehouseId);
    }
}
