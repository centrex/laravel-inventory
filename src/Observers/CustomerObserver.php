<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Models\Customer;
use Centrex\Inventory\Support\ErpIntegration;

class CustomerObserver
{
    public function saved(Customer $customer): void
    {
        app(ErpIntegration::class)->syncCustomer($customer);
    }
}
