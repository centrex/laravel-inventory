<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SalesReportPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);
    Carbon::setTestNow(Carbon::parse('2026-02-15'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('defaults to month-to-date on mount', function (): void {
    $page = new SalesReportPage();
    $page->mount();

    expect($page->dateRange)->toBe('this_month')
        ->and($page->startDate)->toBe('2026-02-01')
        ->and($page->endDate)->toBe('2026-02-15');
});

it('switches to the full prior calendar month', function (): void {
    $page = new SalesReportPage();
    $page->mount();

    $page->dateRange = 'last_month';
    $page->updatedDateRange();

    expect($page->startDate)->toBe('2026-01-01')
        ->and($page->endDate)->toBe('2026-01-31');
});

it('switches to quarter-to-date', function (): void {
    $page = new SalesReportPage();
    $page->mount();

    $page->dateRange = 'this_quarter';
    $page->updatedDateRange();

    expect($page->startDate)->toBe('2026-01-01')
        ->and($page->endDate)->toBe('2026-02-15');
});

it('switches to the full prior calendar quarter', function (): void {
    $page = new SalesReportPage();
    $page->mount();

    $page->dateRange = 'last_quarter';
    $page->updatedDateRange();

    expect($page->startDate)->toBe('2025-10-01')
        ->and($page->endDate)->toBe('2025-12-31');
});
