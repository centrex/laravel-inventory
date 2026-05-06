# Customers & Suppliers

## Customer management

```php
use Centrex\Inventory\Facades\Inventory;

$customer = Inventory::createCustomer([
    'code'                => 'CUST-001',
    'name'                => 'Rahman Brothers Ltd',
    'email'               => 'accounts@rahman.com',
    'phone'               => '+880 1711-000000',
    'currency'            => 'BDT',
    'credit_limit_amount' => 500000.00,    // 0 = no credit limit enforced
    'price_tier_code'     => 'b2b_wholesale',
    'is_active'           => true,
]);

Inventory::updateCustomer($customer->id, [
    'credit_limit_amount' => 750000.00,
]);

Inventory::deleteCustomer($customer->id);   // soft-delete

// Find by id
$customer = Inventory::findCustomer($id);

// Search / list
$customers = Inventory::listCustomers(activeOnly: false, search: 'Rahman');
```

### Linking a customer to an Eloquent model

```php
// Attach to any model (User, Contact, Company, etc.)
$customer = Inventory::findCustomerForModel(App\Models\User::class, $userId);

// Or set on create:
Inventory::createCustomer([
    'name'             => 'Karim',
    'modelable_type'   => App\Models\User::class,
    'modelable_id'     => $userId,
    // ...
]);
```

The `Customer` model exposes the morph as `modelable()`.

---

## Credit limit

When a sale order is created, the system checks the customer's credit exposure (sum of all outstanding open SO totals in base currency):

```php
$so = Inventory::createSaleOrder([...]);

if ($so->credit_override_required) {
    // This order would put the customer over their credit limit.
    // Must be approved by a user with: inventory.sale-orders.approve-credit gate
    echo $so->credit_exposure_before_amount;  // exposure before this order
    echo $so->credit_exposure_after_amount;   // exposure if this order is approved
    echo $so->credit_limit_amount;            // customer's limit at time of order
}
```

Real-time credit snapshot:

```php
$snapshot = Inventory::customerCreditSnapshot($customer->id);
// [
//   'credit_limit_amount'  => 500000.0,
//   'outstanding_exposure' => 123400.0,   // sum of open SO totals
//   'available_credit'     => 376600.0,
// ]
```

---

## Customer order history

```php
$orders = Inventory::customerHistory($customer->id, limit: 10);
// Returns last N sale orders with items
```

---

## Suppliers

```php
use Centrex\Inventory\Models\Supplier;

$supplier = Supplier::create([
    'code'          => 'SUP-CN-001',
    'name'          => 'Shenzhen Electronics Co.',
    'country_code'  => 'CN',
    'currency'      => 'CNY',
    'contact_name'  => 'Li Wei',
    'contact_email' => 'liwei@szcorp.cn',
    'contact_phone' => '+86 755-1234567',
    'address'       => '123 Industrial Rd, Shenzhen',
    'is_active'     => true,
]);
```

Supplier is linked to a vendor in `laravel-accounting` automatically via `SupplierObserver` when `INVENTORY_ACCOUNTING_ENABLED=true`. The `accounting_vendor_id` field stores the accounting vendor ID.

Like customers, suppliers support `modelable_type` / `modelable_id` to morph to any other model.
