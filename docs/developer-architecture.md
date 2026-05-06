# Inventory Developer Architecture

This package keeps most public workflows behind `Centrex\Inventory\Inventory`. The service is intentionally a facade-style API for controllers, Livewire pages, jobs, and tests. When a workflow becomes analytical or cross-package, move the implementation into `src/Support` and keep only a small delegating method on `Inventory`.

## Main Boundaries

| Area | Primary classes | Responsibility |
| --- | --- | --- |
| Public service API | `Inventory` | Stable entry point for package consumers |
| Web dashboard | `Http\Controllers\Web\DashboardController`, `resources/views/dashboard.blade.php` | Dashboard data selection and presentation |
| Sales access | `Support\CommercialTeamAccess`, `Models\CommercialTeamMember` | Sales and purchase visibility scope by team hierarchy |
| Forecasting | `Inventory::salesForecast()` | Demand, stock, cash, and customer forecast |
| Sales targets | `Support\SalesTargetCalculator` | Target revenue model from sales, expense, and payroll history |
| Accounting sync | `Support\ErpIntegration` plus accounting package models | Customer/vendor/accounting document links |

## Sales Target Flow

The dashboard calls:

```php
app(Inventory::class)->salesTarget(
    lookbackDays: 90,
    targetDays: 30,
    expectedGrossMarginPct: null,
    desiredNetMarginPct: 10.0,
    growthPct: 0.0,
    expenseAllocationPct: null,
);
```

`Inventory::salesTarget()` delegates to `SalesTargetCalculator`. The calculator:

1. Loads recent completed/active sales orders and applies `CommercialTeamAccess::applySalesScope()`.
2. Calculates actual revenue, COGS, gross profit, gross margin, and recent daily revenue.
3. Finds active sales-team members visible to the current user.
4. Reads posted accounting expenses when `centrex/laravel-accounting` is installed.
5. Reads approved payroll lines, or falls back to linked employee monthly salary when payroll runs are not posted yet.
6. Converts the lookback costs into a target-period cost base.
7. Converts cost base into target revenue using gross margin, desired net margin, and growth inputs.

The accounting and payroll dependencies are runtime optional. Keep that behavior. Do not add hard Composer dependencies for target reporting unless the package itself starts requiring those modules.

## Complexity Rules

- Keep document mutation workflows on `Inventory` only when they are short orchestration methods.
- Move reporting, forecasting, or cross-package calculations to `src/Support`.
- Keep optional package reads behind `class_exists()` and schema checks.
- Always apply sales or purchase scopes before returning user-facing commercial data.
- Prefer small named methods over comments that explain a long block.

## Documentation Checklist

When adding a workflow:

- Add a short package doc in `docs/`.
- Document the public service method and the matching web/API entry point.
- Name the accounting journal entries created by the workflow.
- Explain whether the workflow is reversible, voidable, or final.
