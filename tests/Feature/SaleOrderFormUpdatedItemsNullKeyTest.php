<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleOrderFormPage;
use Illuminate\Support\Facades\Gate;

it('does not crash when livewire reports a whole-array items update with a null key', function (): void {
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);

    $page = new SaleOrderFormPage();
    $page->mount();

    $page->updatedItems($page->items, null);
})->throwsNoExceptions();
