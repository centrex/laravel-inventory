<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Models\Supplier;
use Centrex\Inventory\Support\ErpIntegration;

class SupplierObserver
{
    public function saved(Supplier $supplier): void
    {
        app(ErpIntegration::class)->syncSupplier($supplier);
    }
}
