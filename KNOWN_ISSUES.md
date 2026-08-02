# Known Issues — laravel-inventory

_Last checked: 2026-08-02_

`vendor/` was already present; no install needed. Confirmed reproducible in both `vendor/bin/pest -p` (parallel) and `vendor/bin/pest` (serial) — same 29 failures either way, so these are not parallel-test-isolation flakes.

## Failing tests

`vendor/bin/pest` — **29 failed, 1 skipped, 100 passed** (329 assertions), ~13-50s (well within time-box). Failures group into a few root causes:

1. **Missing `phpoffice/phpspreadsheet` in `vendor/`, despite being a `require` (not dev) dependency in `composer.json` (`^2.0`)** — it is not present in `composer.lock` at all (stale lock file relative to `composer.json`). Causes:
   - `Tests\Feature\AgingReportExportTest` — both tests: `Error: Class "PhpOffice\PhpSpreadsheet\Spreadsheet" not found` at `src/Support/AgingReportExcelExporter.php:26`.
   - `Tests\Feature\InventoryReportsExportTest > it exports every…` — same error at `src/Support/InventoryReportsExporter.php:75`.
   - `Tests\Feature\SalesReportExportTest` (added 2026-08-02, see below) — same error, at `src/Support/SalesReportExcelExporter.php:55`. Confirmed by temporarily running the sibling export tests side by side — identical root cause, not something the new exporter introduced.
   - Fix pointer: run `composer update phpoffice/phpspreadsheet` (or a full `composer update`) to bring the lock file/vendor in sync with `composer.json`.
   - **Investigated further (2026-08-02)**: `composer update phpoffice/phpspreadsheet` fails outright — it's not a simple missing-package gap, `composer.json`'s own version constraints have drifted from what's actually available/symlinked via this workspace's path repos: it requires `centrex/laravel-accounting: ^3.7` (only `v3.7.0`–`v3.8.5` on Packagist satisfy that; the local path repo's `dev-main` doesn't and is canonical/higher-priority, so it's unresolvable), `centrex/laravel-addresses: ^1.4` (same shape of conflict), `centrex/laravel-courier: ^0.3` (same), and `centrex/laravel-model-data: ^0.1` (doesn't match any of `dev-main, v1.0.0…v1.2.0`). None of this is related to `phpoffice/phpspreadsheet` itself — it's pre-existing version drift across this package's own dependency constraints vs. its sibling packages' current versions, and needs a deliberate `composer.json` cleanup (likely bumping these constraints or switching to `dev-main`/`*` to match how the path repos are actually consumed) before `composer update` can succeed at all, for this or any other package.

2. **Installed `centrex/tallui` (v1.2.5, `composer.json` constraint is `*`) doesn't expose `Column::can()`** — `Tests\Feature\WarehouseStockTableTest > it merges sku into…`: `Error: Call to undefined method Centrex\TallUi\DataTable\Column::can()` at `src/Http/Livewire/Entities/WarehouseStockTable.php:46`. Same class of issue previously seen in `laravel-accounting`'s `KNOWN_ISSUES.md` (missing `Column::currency()`) — the unpinned `centrex/tallui: *` constraint is resolving to a version that lags behind what this package's `Column::...->can(...)` fluent calls expect.

3. **`MissingAppKeyException` — "No application encryption key has been specified"** — 6x in `Tests\Feature\AsyncSelectControllerTest`, 3x in `Tests\Feature\AgingReportPageTabsTest`, 1x in `Tests\Feature\InventoryWorkflowTest > it updates d…`, 1x in `Tests\Feature\ShipmentReconciliationTest > it show…`. All throw from `vendor/laravel/framework/.../EncryptionServiceProvider.php:83` while rendering a Blade view during the test — the testbench workbench app used for these specific tests has no `APP_KEY` configured (other tests in the same run don't hit this, so it's scoped to whichever tests boot a view needing encryption, e.g. signed/encrypted session or CSRF-bound components).

4. **"Accounting account [1300] is not available for ERP integration"** (`RuntimeException` from `src/Support/ErpIntegration.php:886`, called from `:485`) — 3x in `Tests\Feature\StockAgingReportTest`, 1x in `Tests\Feature\InventoryWorkflowTest > it can pr…`. The test's accounting fixture/seed doesn't have an active account with code `1300` (Inventory Asset) when the ERP bridge tries to resolve it — looks like a test-data setup gap rather than app code, but worth checking whether the `centrex/laravel-accounting` chart-of-accounts seeding contract changed.

5. **"Stock is already reserved for sale order #1"** (`Centrex\Inventory\Exceptions\InvalidTransitionException` at `src/Inventory.php:1864`) — surfaces in `Tests\Feature\SalesReportInvoiceScopingTest`, and twice in `Tests\Feature\InventoryWorkflowTest` (once directly, once masking an expected `InvalidArgumentException` in "it prevents fulfilling more…"). Pattern suggests `reserveStock()` is being invoked twice against the same `SaleOrder` (once in shared test setup, once in the test body) — either the shared fixture already reserves stock, or the guard added in `Inventory.php:1864` is stricter than the tests assumed.

6. `Tests\Feature\InventoryWorkflowTest > it can confirm and cancel a…` — `confirmSaleOrder()` on a `quotation`-type document is expected to leave `status` as `'confirmed'` but the order ends up `'processing'` instead — looks like quotation confirmation is falling through to the same path as a regular order's reserve-stock step rather than stopping at `confirmed`.
7. `Tests\Feature\InventoryWorkflowTest > it exposes inventory api ro…` — expects `200` from an API route but gets `403` (permission/gate mismatch for the test's user).
8. `Tests\Feature\InventoryWorkflowTest > it blocks sale orders that…` — expected an `InvalidArgumentException` to be thrown; none was.
9. `Tests\Feature\CourierParcelTest` — 2 failures: `expect(session()->get('status'))->toContain(...)` throws `InvalidExpectationValue: Expected [iterable]` — `session('status')` is apparently `null`/non-iterable in both the courier-API and hand-carry parcel flows, i.e. the flash message isn't being written under the `status` session key the tests expect.
10. `Tests\Feature\ExchangeRateIntegrationTest > it…` — `RuntimeException: No exchange rate found for currency [EUR] on or before [2026-08-02]` at `src/Inventory.php:165` — test relies on either a stored rate or the live-fetch fallback, neither of which resolved for EUR on today's date in this environment.

## Style / static-analysis debt

- `vendor/bin/pint --test` reports **38 files** flagged, mostly `new_with_braces` (plus `class_definition`/`braces_position` in several `database/migrations/*` files) across `src/`, `tests/Feature/`, and migrations. Run `composer lint` to apply.
- `vendor/bin/rector --dry-run` reports **49 files** would change (`SimplifyEmptyCheckOnEmptyArrayRector`, `AddArrowFunctionReturnTypeRector`). Run `composer refacto` to apply.
- `vendor/bin/phpstan analyse` (level `max`) — **passes clean**, but only because `phpstan-baseline.neon` suppresses roughly **2,020 baselined error signatures** (10k-line baseline file). The baseline hides real issues from newly surfacing; it does not mean the flagged code is type-safe.

## TODO / FIXME markers

None found (`grep -rn "TODO\|FIXME" --include="*.php" src/ config/ database/`).

## Fixed (2026-08-02, from a production log)

`InventoryDueAgingCard`'s "Due Aging" tab crashed in production with:

```text
The script tried to call a method on an incomplete object. Please ensure that the class
definition "Carbon\CarbonImmutable" ... was loaded _before_ unserialize() gets called
```

`dueAgingOrders()` already had a comment explaining it caches a plain array (not a
`Collection`) specifically to avoid `__PHP_Incomplete_Class` surviving into the cache
store's `serialize()`/`unserialize()` round-trip — but missed the identical hazard one
level down: each row's `ordered_at` is a raw `Carbon` instance, cached as-is inside that
"safe" array. Fixed by stringifying `ordered_at` (`toISOString()`) before it enters the
cache and parsing it back to `Carbon` after retrieval, so `render()`'s
`$row['ordered_at']?->format(...)` keeps working unchanged. Could not run this component's
tests to confirm (blocked by the `phpoffice/phpspreadsheet`/composer-drift issue above,
unrelated to this fix) — verified via `vendor/bin/phpstan analyse` (clean) and `php -l`.

Also (same session, user-requested, not bug fixes): reordered the Aging Report page's tabs
so "Due Aging" is the default/first tab (`resources/views/livewire/transactions/aging-report.blade.php`),
and added an "SO Numbers" column (comma-separated) to the per-customer Due Aging Excel
export (`AgingReportExcelExporter::writeDueAgingSheet()` + `groupDueAgingByCustomer()`) —
previously only the combined "Export All" workbook's due-aging sheet had SO numbers
(it's built from ungrouped per-order rows), the per-tab grouped export didn't.

**Fixed a follow-on bug in that SO Numbers change**: a later manual edit to
`writeDueAgingSheet()` moved the `SO Numbers` header to the end of the header array but
left the corresponding value in the row-mapping array in its original (4th) position —
every column from `0-30` onward was silently mislabeled (e.g. the `0-30` header displayed
`so_numbers` data, `Total Due` displayed the `90+` bucket, and the `SO Numbers` header
displayed `total_due`). Re-aligned the row array to match the header order.

Added (2026-08-02, user-requested): "Last Quarter" preset on `SalesReportPage`'s date-range
selector (alongside the existing This Month/Last Month/This Quarter — see
`tests/Feature/SalesReportDateRangeTest.php`), and a new 3-tab Excel export for the Sales
Report page (`SalesReportExcelExporter` — Sale Statistics / Recent Sales / Sold Products,
mirroring the page's own tabs). "Recent Sales" and "Sold Products" export the _full_ matching
dataset, not the capped on-screen rows (25/50 respectively) — same convention as
`InventoryReportsExporter`. "Sale Statistics" reuses `InventorySalesStatisticsCard`'s own
`statistics()` via direct instantiation + reflection (bypassing `mount()`'s
`Gate::authorize()` and `CachesData`'s cache, matching the pattern already established in
`tests/Feature/SalesReportInvoiceScopingTest.php`) rather than duplicating that computation.
`tests/Feature/SalesReportExportTest.php` added but currently fails — same pre-existing
`phpoffice/phpspreadsheet` class-not-found issue as the other two export tests, confirmed by
comparing failures side by side, not a defect in the new exporter.

## Open GitHub issues

Not checked — the `gh` CLI is not installed in this environment.
