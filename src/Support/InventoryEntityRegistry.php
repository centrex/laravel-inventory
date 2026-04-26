<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\{CommercialTeamMember, Coupon, Customer, Product, ProductBrand, ProductCategory, ProductPrice, ProductVariant, ProductVariantAttributeType, ProductVariantAttributeValue, Supplier, Warehouse, WarehouseProduct};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\{Arr, Str};
use Illuminate\Validation\Rule;

class InventoryEntityRegistry
{
    public static function entities(): array
    {
        return [
            'warehouses' => [
                'label'         => 'Warehouses',
                'singular'      => 'Warehouse',
                'model'         => Warehouse::class,
                'search'        => ['code', 'name', 'country_code', 'currency'],
                'index_columns' => ['code', 'name', 'country_code', 'currency', 'is_active', 'is_default'],
                'form_fields'   => [
                    self::field('code', 'text', ['required', 'string', 'max:20']),
                    self::field('name', 'text', ['required', 'string', 'max:200']),
                    self::field('country_code', 'text', ['required', 'string', 'size:2']),
                    self::field('currency', 'text', ['required', 'string', 'size:3']),
                    self::field('address', 'textarea', ['nullable', 'string']),
                    self::field('is_active', 'checkbox', ['boolean'], false),
                    self::field('is_default', 'checkbox', ['boolean'], false),
                    self::field('meta', 'json', ['nullable', 'array'], []),
                ],
            ],
            'product-categories' => [
                'label'         => 'Product Categories',
                'singular'      => 'Product Category',
                'model'         => ProductCategory::class,
                'search'        => ['name', 'slug'],
                'index_columns' => ['name', 'slug', 'parent_id', 'sort_order', 'is_active'],
                'form_fields'   => [
                    self::field('parent_id', 'select', ['nullable', 'integer', 'exists:' . (new ProductCategory())->getTable() . ',id'], null, ProductCategory::class, 'name'),
                    self::field('name', 'text', ['required', 'string', 'max:200']),
                    self::field('slug', 'text', ['required', 'string', 'max:200']),
                    self::field('description', 'textarea', ['nullable', 'string']),
                    self::field('sort_order', 'number', ['nullable', 'integer', 'min:0'], 0),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                ],
            ],
            'product-brands' => [
                'label'         => 'Product Brands',
                'singular'      => 'Product Brand',
                'model'         => ProductBrand::class,
                'search'        => ['name', 'slug'],
                'index_columns' => ['name', 'slug', 'sort_order', 'is_active'],
                'form_fields'   => [
                    self::field('name', 'text', ['required', 'string', 'max:200']),
                    self::field('slug', 'text', ['required', 'string', 'max:200']),
                    self::field('description', 'textarea', ['nullable', 'string']),
                    self::field('sort_order', 'number', ['nullable', 'integer', 'min:0'], 0),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                ],
            ],
            'products' => [
                'label'         => 'Products',
                'singular'      => 'Product',
                'model'         => Product::class,
                'search'        => ['sku', 'name', 'barcode'],
                'index_columns' => ['sku', 'name', 'category_id', 'brand_id', 'unit', 'weight_kg', 'is_active', 'is_stockable'],
                'form_fields'   => [
                    self::field('category_id', 'select', ['nullable', 'integer', 'exists:' . (new ProductCategory())->getTable() . ',id'], null, ProductCategory::class, 'name'),
                    self::field('brand_id', 'select', ['nullable', 'integer', 'exists:' . (new ProductBrand())->getTable() . ',id'], null, ProductBrand::class, 'name'),              
                    self::field('sku', 'text', ['required', 'string', 'max:100']),
                    self::field('name', 'text', ['required', 'string', 'max:300']),
                    self::field('description', 'textarea', ['nullable', 'string']),
                    self::field('unit', 'text', ['required', 'string', 'max:30'], 'pcs'),
                    self::field('weight_kg', 'number', ['nullable', 'numeric', 'min:0']),
                    self::field('barcode', 'text', ['nullable', 'string', 'max:100']),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                    self::field('is_stockable', 'checkbox', ['boolean'], true),
                    self::field('variant_names', 'json', ['nullable', 'array'], null, null, null, null, 'Variant Names (e.g. {"size":"Large","color":"Red"})'),
                    self::field('meta', 'json', ['nullable', 'array'], null),
                ],
            ],
            'suppliers' => [
                'label'         => 'Suppliers',
                'singular'      => 'Supplier',
                'model'         => Supplier::class,
                'search'        => ['code', 'name', 'country_code', 'demographic_segment', 'contact_email', 'contact_phone'],
                'index_columns' => ['code', 'name', 'country_code', 'demographic_segment', 'currency', 'contact_email', 'purchase_manager_id', 'purchase_executive_id', 'is_active'],
                'form_fields'   => [
                    self::field('code', 'text', ['required', 'string', 'max:30']),
                    self::field('name', 'text', ['required', 'string', 'max:300']),
                    self::field('country_code', 'text', ['nullable', 'string', 'size:2']),
                    self::field('demographic_segment', 'text', ['nullable', 'string', 'max:120']),
                    self::field('demographic_data', 'json', ['nullable', 'array'], []),
                    self::field('currency', 'text', ['required', 'string', 'size:3'], 'BDT'),
                    self::field('contact_name', 'text', ['nullable', 'string', 'max:200']),
                    self::field('contact_email', 'email', ['nullable', 'email', 'max:200']),
                    self::field('contact_phone', 'text', ['nullable', 'string', 'max:50']),
                    self::field('address', 'textarea', ['nullable', 'string']),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                    self::field('purchase_manager_id', 'select', ['nullable', 'integer', 'exists:users,id'], null, self::userModel(), 'name'),
                    self::field('purchase_assistant_manager_id', 'select', ['nullable', 'integer', 'exists:users,id'], null, self::userModel(), 'name'),
                    self::field('purchase_executive_id', 'select', ['nullable', 'integer', 'exists:users,id'], null, self::userModel(), 'name'),
                    self::field('meta', 'json', ['nullable', 'array'], []),
                ],
            ],
            'customers' => [
                'label'         => 'Customers',
                'singular'      => 'Customer',
                'model'         => Customer::class,
                'search'        => ['code', 'name', 'organization_name', 'email', 'phone', 'zone', 'area', 'demographic_segment'],
                'index_columns' => ['code', 'name', 'organization_name', 'email', 'phone', 'zone', 'area', 'demographic_segment', 'currency', 'credit_limit_amount', 'price_tier_code', 'sales_owner_id', 'sales_owner_designation', 'is_active'],
                'form_fields'   => [
                    self::field('code', 'text', ['required', 'string', 'max:30']),
                    self::field('name', 'text', ['required', 'string', 'max:300']),
                    self::field('organization_name', 'text', ['nullable', 'string', 'max:300']),
                    self::field('email', 'email', ['nullable', 'email', 'max:200']),
                    self::field('phone', 'text', ['nullable', 'string', 'max:50']),
                    self::field('zone', 'text', ['nullable', 'string', 'max:120']),
                    self::field('area', 'text', ['nullable', 'string', 'max:120']),
                    self::field('demographic_segment', 'text', ['nullable', 'string', 'max:120']),
                    self::field('demographic_data', 'json', ['nullable', 'array'], []),
                    self::field('currency', 'text', ['required', 'string', 'size:3'], 'BDT'),
                    self::field('credit_limit_amount', 'number', ['nullable', 'numeric', 'min:0'], 0),
                    self::field('price_tier_code', 'select', ['nullable', 'string', Rule::in(PriceTierCode::values())], null, null, null, PriceTierCode::options()),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                    self::field('sales_owner_id', 'select', ['nullable', 'required_with:sales_owner_designation', 'integer', 'exists:users,id'], null, self::userModel(), 'name', null, 'Sales owner'),
                    self::field('sales_owner_designation', 'select', ['nullable', 'required_with:sales_owner_id', 'string', Rule::in(['manager', 'assistant_manager', 'executive'])], null, null, null, CommercialTeamAccess::roleOptions(), 'Sales owner designation'),
                    self::field('meta', 'json', ['nullable', 'array'], []),
                ],
            ],
            'commercial-team-members' => [
                'label'         => 'Commercial Team Members',
                'singular'      => 'Commercial Team Member',
                'model'         => CommercialTeamMember::class,
                'search'        => ['workflow', 'role'],
                'index_columns' => ['workflow', 'user_id', 'manager_user_id', 'role', 'is_active'],
                'form_fields'   => [
                    self::field('workflow', 'select', ['required', 'string', Rule::in(['sales', 'purchase'])], 'sales', null, null, CommercialTeamAccess::workflowOptions()),
                    self::field('user_id', 'select', ['required', 'integer', 'exists:users,id'], null, self::userModel(), 'name'),
                    self::field('manager_user_id', 'select', ['nullable', 'integer', 'exists:users,id'], null, self::userModel(), 'name'),
                    self::field('role', 'select', ['required', 'string', Rule::in(['manager', 'assistant_manager', 'executive'])], 'executive', null, null, CommercialTeamAccess::roleOptions()),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                    self::field('meta', 'json', ['nullable', 'array'], []),
                ],
            ],
            'coupons' => [
                'label'         => 'Coupons',
                'singular'      => 'Coupon',
                'model'         => Coupon::class,
                'search'        => ['code', 'name', 'description'],
                'index_columns' => ['code', 'name', 'discount_type', 'discount_value', 'minimum_subtotal_amount', 'maximum_discount_amount', 'usage_limit', 'starts_at', 'ends_at', 'is_active'],
                'form_fields'   => [
                    self::field('code', 'text', ['required', 'string', 'max:50']),
                    self::field('name', 'text', ['nullable', 'string', 'max:150']),
                    self::field('description', 'textarea', ['nullable', 'string']),
                    self::field('discount_type', 'select', ['required', 'string', Rule::in(['fixed', 'percent'])], null, null, null, [
                        ['code' => 'fixed', 'name' => 'Fixed amount'],
                        ['code' => 'percent', 'name' => 'Percentage'],
                    ]),
                    self::field('discount_value', 'number', ['required', 'numeric', 'gt:0']),
                    self::field('minimum_subtotal_amount', 'number', ['nullable', 'numeric', 'min:0']),
                    self::field('maximum_discount_amount', 'number', ['nullable', 'numeric', 'min:0']),
                    self::field('usage_limit', 'number', ['nullable', 'integer', 'min:1']),
                    self::field('starts_at', 'date', ['nullable', 'date']),
                    self::field('ends_at', 'date', ['nullable', 'date', 'after_or_equal:starts_at']),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                    self::field('meta', 'json', ['nullable', 'array'], []),
                ],
            ],
            'product-variants' => [
                'label'         => 'Product Variants',
                'singular'      => 'Product Variant',
                'model'         => ProductVariant::class,
                'search'        => ['sku', 'name', 'barcode'],
                'index_columns' => ['product_id', 'sku', 'name', 'barcode', 'weight_kg', 'sort_order', 'is_active'],
                'form_fields'   => [
                    self::field('product_id', 'select', ['required', 'integer', 'exists:' . (new Product())->getTable() . ',id'], null, Product::class, 'name'),
                    self::field('sku', 'text', ['required', 'string', 'max:100']),
                    self::field('name', 'text', ['required', 'string', 'max:300']),
                    self::field('barcode', 'text', ['nullable', 'string', 'max:100']),
                    self::field('weight_kg', 'number', ['nullable', 'numeric', 'min:0']),
                    self::field('sort_order', 'number', ['nullable', 'integer', 'min:0'], 0),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                    self::field('attributes', 'json', ['nullable', 'array'], []),
                    self::field('meta', 'json', ['nullable', 'array'], []),
                ],
            ],
            'variant-attribute-types' => [
                'label'         => 'Variant Attribute Types',
                'singular'      => 'Attribute Type',
                'model'         => ProductVariantAttributeType::class,
                'search'        => ['name', 'slug'],
                'index_columns' => ['name', 'slug', 'sort_order'],
                'form_fields'   => [
                    self::field('name', 'text', ['required', 'string', 'max:100']),
                    self::field('slug', 'text', ['required', 'string', 'max:100']),
                    self::field('sort_order', 'number', ['nullable', 'integer', 'min:0'], 0),
                ],
            ],
            'variant-attribute-values' => [
                'label'         => 'Variant Attribute Values',
                'singular'      => 'Attribute Value',
                'model'         => ProductVariantAttributeValue::class,
                'search'        => ['value', 'display_value'],
                'index_columns' => ['attribute_type_id', 'value', 'display_value', 'color_hex', 'sort_order'],
                'form_fields'   => [
                    self::field('attribute_type_id', 'select', ['required', 'integer', 'exists:' . (new ProductVariantAttributeType())->getTable() . ',id'], null, ProductVariantAttributeType::class, 'name'),
                    self::field('value', 'text', ['required', 'string', 'max:150']),
                    self::field('display_value', 'text', ['nullable', 'string', 'max:150']),
                    self::field('color_hex', 'text', ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/']),
                    self::field('sort_order', 'number', ['nullable', 'integer', 'min:0'], 0),
                ],
            ],
            'product-prices' => [
                'label'         => 'Product Prices',
                'singular'      => 'Product Price',
                'model'         => ProductPrice::class,
                'search'        => [],
                'index_columns' => ['product_id', 'price_tier_code', 'warehouse_id', 'price_amount', 'cost_price', 'moq', 'preorder_moq', 'currency', 'effective_from', 'effective_to', 'is_active'],
                'form_fields'   => [
                    self::field('product_id', 'select', ['required', 'integer', 'exists:' . (new Product())->getTable() . ',id'], null, Product::class, 'name'),
                    self::field('price_tier_code', 'select', ['required', 'string', Rule::in(PriceTierCode::values())], null, null, null, PriceTierCode::options()),
                    self::field('warehouse_id', 'select', ['nullable', 'integer', 'exists:' . (new Warehouse())->getTable() . ',id'], null, Warehouse::class, 'name'),
                    self::field('price_amount', 'number', ['required', 'numeric', 'min:0']),
                    self::field('cost_price', 'number', ['nullable', 'numeric', 'min:0']),
                    self::field('moq', 'number', ['nullable', 'integer', 'min:1'], 1),
                    self::field('preorder_moq', 'number', ['nullable', 'integer', 'min:1']),
                    self::field('price_local', 'number', ['nullable', 'numeric', 'min:0']),
                    self::field('currency', 'text', ['nullable', 'string', 'size:3']),
                    self::field('effective_from', 'date', ['nullable', 'date']),
                    self::field('effective_to', 'date', ['nullable', 'date', 'after_or_equal:effective_from']),
                    self::field('is_active', 'checkbox', ['boolean'], true),
                ],
            ],
            'warehouse-products' => [
                'label'         => 'Warehouse Stock',
                'singular'      => 'Warehouse Stock',
                'model'         => WarehouseProduct::class,
                'search'        => [],
                'index_columns' => ['warehouse_id', 'product_id', 'qty_on_hand', 'qty_reserved', 'qty_in_transit', 'wac_amount', 'reorder_point'],
                'form_fields'   => [
                    self::field('warehouse_id', 'select', ['required', 'integer', 'exists:' . (new Warehouse())->getTable() . ',id'], null, Warehouse::class, 'name'),
                    self::field('product_id', 'select', ['required', 'integer', 'exists:' . (new Product())->getTable() . ',id'], null, Product::class, 'name'),
                    self::field('qty_on_hand', 'number', ['required', 'numeric']),
                    self::field('qty_reserved', 'number', ['required', 'numeric']),
                    self::field('qty_in_transit', 'number', ['required', 'numeric']),
                    self::field('wac_amount', 'number', ['required', 'numeric', 'min:0']),
                    self::field('reorder_point', 'number', ['nullable', 'numeric']),
                    self::field('reorder_qty', 'number', ['nullable', 'numeric']),
                    self::field('bin_location', 'text', ['nullable', 'string', 'max:100']),
                ],
            ],
        ];
    }

    public static function masterDataEntities(): array
    {
        return array_keys(self::entities());
    }

    public static function definition(string $entity): array
    {
        $definition = self::entities()[$entity] ?? null;

        if (!$definition) {
            throw new \InvalidArgumentException("Unknown inventory entity [{$entity}].");
        }

        return $definition;
    }

    public static function modelClass(string $entity): string
    {
        return self::definition($entity)['model'];
    }

    public static function makeModel(string $entity): Model
    {
        $modelClass = self::modelClass($entity);

        return new $modelClass();
    }

    public static function validationRules(string $entity, ?Model $record = null, array $payload = []): array
    {
        $definition = self::definition($entity);
        $rules = [];
        $model = self::makeModel($entity);
        $table = $model->getTable();

        foreach ($definition['form_fields'] as $field) {
            $fieldRules = $field['rules'];

            if (in_array($field['name'], ['code', 'slug', 'sku', 'barcode'], true)) {
                $fieldRules[] = Rule::unique($table, $field['name'])->ignore($record?->getKey());
            }

            if ($entity === 'variant-attribute-values' && $field['name'] === 'value') {
                $typeId = $payload['attribute_type_id'] ?? null;
                $fieldRules[] = Rule::unique($table, 'value')
                    ->where('attribute_type_id', $typeId)
                    ->ignore($record?->getKey());
            }

            if ($entity === 'customers' && $field['name'] === 'sales_owner_id') {
                $visibleSalesUsers = CommercialTeamAccess::visibleUserIds('sales');

                if ($visibleSalesUsers !== null) {
                    $fieldRules[] = Rule::in($visibleSalesUsers);
                }
            }

            $rules[$field['name']] = $fieldRules;
        }

        if ($entity === 'customers') {
            $rules['sales_manager_id'] = ['nullable', 'integer', 'exists:users,id'];
            $rules['sales_assistant_manager_id'] = ['nullable', 'integer', 'exists:users,id'];
            $rules['sales_executive_id'] = ['nullable', 'integer', 'exists:users,id'];
        }

        return $rules;
    }

    public static function fillablePayload(string $entity, array $payload): array
    {
        $definition = self::definition($entity);
        $output = [];

        foreach ($definition['form_fields'] as $field) {
            $name = $field['name'];
            $value = Arr::get($payload, $name, $field['default']);

            if ($field['type'] === 'checkbox') {
                $value = (bool) $value;
            }

            if ($field['type'] === 'json') {
                $value = is_string($value) && $value !== ''
                    ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
                    : (is_array($value) ? $value : null);
            }

            if ($value === '' && str_contains(implode('|', $field['rules']), 'nullable')) {
                $value = null;
            }

            if (is_string($value) && in_array($field['type'], ['text', 'email'], true)) {
                $value = trim($value);
                $value = $field['name'] === 'currency' || $field['name'] === 'country_code'
                    ? Str::upper($value)
                    : $value;
            }

            $output[$name] = $value;
        }

        if ($entity === 'customers') {
            $output = [
                ...$output,
                ...CommercialTeamAccess::assignmentForUserDesignation(
                    'sales',
                    isset($output['sales_owner_id']) ? (int) $output['sales_owner_id'] : null,
                    $output['sales_owner_designation'] ?? null,
                ),
            ];
        }

        return $output;
    }

    public static function defaultFormData(string $entity): array
    {
        $definition = self::definition($entity);
        $defaults = [];

        foreach ($definition['form_fields'] as $field) {
            $defaults[$field['name']] = $field['type'] === 'json' && is_array($field['default'])
                ? json_encode($field['default'], JSON_PRETTY_PRINT)
                : $field['default'];
        }

        return $defaults;
    }

    public static function formOptions(string $entity): array
    {
        $definition = self::definition($entity);
        $options = [];

        foreach ($definition['form_fields'] as $field) {
            if (($field['type'] ?? null) !== 'select') {
                continue;
            }

            if (!empty($field['options'])) {
                $options[$field['name']] = array_map(
                    static function (mixed $option): array {
                        if (!is_array($option)) {
                            return [
                                'value' => (string) $option,
                                'label' => (string) $option,
                            ];
                        }

                        return [
                            'value' => (string) ($option['value'] ?? $option['code'] ?? $option['id'] ?? ''),
                            'label' => (string) ($option['label'] ?? $option['name'] ?? $option['title'] ?? $option['value'] ?? $option['code'] ?? ''),
                        ];
                    },
                    $field['options'],
                );

                continue;
            }

            if (empty($field['related_model'])) {
                continue;
            }

            $related = new $field['related_model']();
            $query = $related->newQuery();

            if ($entity === 'customers' && $field['name'] === 'sales_owner_id') {
                $visibleSalesUsers = CommercialTeamAccess::visibleUserIds('sales');

                if ($visibleSalesUsers !== null) {
                    $query->whereIn($related->getKeyName(), $visibleSalesUsers);
                }
            }

            $options[$field['name']] = $query
                ->orderBy($field['related_label'])
                ->get(['id', $field['related_label']])
                ->map(fn (Model $model) => [
                    'value' => (string) $model->getKey(),
                    'label' => (string) $model->getAttribute($field['related_label']),
                ])
                ->all();
        }

        return $options;
    }

    public static function indexColumns(string $entity): array
    {
        return self::definition($entity)['index_columns'];
    }

    public static function searchableColumns(string $entity): array
    {
        return self::definition($entity)['search'];
    }

    private static function field(
        string $name,
        string $type,
        array $rules,
        mixed $default = null,
        ?string $relatedModel = null,
        ?string $relatedLabel = null,
        ?array $options = null,
        ?string $label = null,
    ): array {
        return [
            'name'          => $name,
            'label'         => $label ?: Str::of($name)->replace('_', ' ')->title()->toString(),
            'type'          => $type,
            'rules'         => $rules,
            'default'       => $default,
            'related_model' => $relatedModel,
            'related_label' => $relatedLabel,
            'options'       => $options,
        ];
    }

    private static function userModel(): string
    {
        return (string) config('auth.providers.users.model', 'App\\Models\\User');
    }
}
