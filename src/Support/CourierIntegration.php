<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Courier\Services\{PathaoService, RedxService};
use Centrex\Inventory\Models\SaleOrder;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Optional integration with centrex/laravel-courier (Pathao / Redx only, for now). Not a hard
 * composer dependency — see enabled(), which mirrors ErpIntegration::enabled()'s class_exists()
 * gating so laravel-inventory works fine without the courier package installed.
 *
 * Courier services are constructed per call with an explicit config array (sandbox or live,
 * chosen per request) rather than resolved from the container, because AbstractCourierService
 * prioritizes constructor-injected config over the package's own global config — this is what
 * lets a user pick sandbox vs production per parcel instead of being locked to one environment.
 */
class CourierIntegration
{
    public function enabled(): bool
    {
        return (bool) config('inventory.courier.enabled', false)
            && class_exists(PathaoService::class)
            && class_exists(RedxService::class);
    }

    /**
     * @param  array<string, mixed>  $fields  recipient_name, recipient_phone, recipient_address,
     *                                        weight_kg, cod_amount, item_description, and
     *                                        (redx only) delivery_area_id
     * @return array{tracking_number: string, raw: array<string, mixed>}
     */
    public function createParcel(SaleOrder $saleOrder, string $provider, string $environment, array $fields): array
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('Courier integration is not enabled.');
        }

        return match ($provider) {
            'pathao' => $this->createPathaoParcel($saleOrder, $environment, $fields),
            'redx'   => $this->createRedxParcel($saleOrder, $environment, $fields),
            default  => throw new \InvalidArgumentException("Unsupported courier provider [{$provider}]."),
        };
    }

    /** @param array<string, mixed> $fields */
    private function createPathaoParcel(SaleOrder $saleOrder, string $environment, array $fields): array
    {
        $config = $this->pathaoConfigFor($environment);
        $service = new PathaoService(app(HttpFactory::class), ['pathao' => $config]);

        $response = $service->createOrder([
            'store_id'          => $config['store_id'],
            'recipient_name'    => (string) $fields['recipient_name'],
            'recipient_phone'   => (string) $fields['recipient_phone'],
            'recipient_address' => (string) $fields['recipient_address'],
            'delivery_type'     => 48, // Normal delivery
            'item_type'         => 2,  // Parcel
            'item_quantity'     => 1,
            'item_weight'       => (float) $fields['weight_kg'],
            'item_description'  => (string) ($fields['item_description'] ?? $saleOrder->so_number),
            'amount_to_collect' => (float) $fields['cod_amount'],
            'merchant_order_id' => $saleOrder->so_number,
        ]);

        return [
            'tracking_number' => (string) ($response['data']['consignment_id'] ?? ''),
            'raw'             => $response,
        ];
    }

    /** @param array<string, mixed> $fields */
    private function createRedxParcel(SaleOrder $saleOrder, string $environment, array $fields): array
    {
        $config = $this->redxConfigFor($environment);
        $service = new RedxService(app(HttpFactory::class), ['redx' => $config]);

        $codAmount = (string) $fields['cod_amount'];
        $itemDescription = (string) ($fields['item_description'] ?? $saleOrder->so_number);

        $response = $service->createParcel([
            'customer_name'          => (string) $fields['recipient_name'],
            'customer_phone'         => (string) $fields['recipient_phone'],
            'delivery_area_id'       => (int) $fields['delivery_area_id'],
            'pickup_area_id'         => (int) $fields['pickup_area_id'],
            'customer_address'       => (string) $fields['recipient_address'],
            'merchant_invoice_id'    => $saleOrder->so_number,
            'cash_collection_amount' => $codAmount,
            'parcel_weight'          => (int) round(((float) $fields['weight_kg']) * 1000), // kg -> grams
            'value'                  => $codAmount,
            'parcel_details_json'    => [
                ['name' => $itemDescription, 'category' => 'Others', 'value' => $codAmount],
            ],
        ]);

        return [
            'tracking_number' => (string) ($response['tracking_id'] ?? ''),
            'raw'             => $response,
        ];
    }

    /**
     * Redx delivery areas for the given environment, normalized to
     * [['id' => int, 'name' => string, 'district_name' => string, 'post_code' => mixed], ...].
     *
     * @return array<int, array<string, mixed>>
     */
    public function redxAreas(string $environment): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $service = new RedxService(app(HttpFactory::class), ['redx' => $this->redxConfigFor($environment)]);

        return array_values((array) ($service->getAreas()['areas'] ?? []));
    }

    /**
     * The merchant's registered Redx pickup stores (each carries its own area) for the
     * given environment — the pickup-area choices offered on the Dispatch Terminal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function redxPickupStores(string $environment): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $service = new RedxService(app(HttpFactory::class), ['redx' => $this->redxConfigFor($environment)]);

        return array_values((array) ($service->getPickupStores()['pickup_stores'] ?? []));
    }

    /** @return array<string, mixed> */
    private function pathaoConfigFor(string $environment): array
    {
        $env = $environment === 'live' ? 'live' : 'sandbox';
        $envConfig = (array) config("inventory.courier.pathao.{$env}", []);
        $baseUrl = (string) ($envConfig['base_url'] ?? '');

        return [
            'sandbox'       => $env === 'sandbox',
            'base_urls'     => ['sandbox' => $baseUrl, 'live' => $baseUrl],
            'client_id'     => $envConfig['client_id'] ?? '',
            'client_secret' => $envConfig['client_secret'] ?? '',
            'username'      => $envConfig['username'] ?? '',
            'password'      => $envConfig['password'] ?? '',
            'store_id'      => config('inventory.courier.pathao.store_id', ''),
            // TokenManager::tokenUrl() throws without an auth endpoint — mirror the
            // laravel-courier package's own pathao auth defaults (config/courier.php).
            'auth' => [
                'endpoint'           => 'aladdin/api/v1/issue-token',
                'method'             => 'post',
                'body_type'          => 'json',
                'response_token_key' => 'access_token',
                'header'             => 'Authorization',
                'prefix'             => 'Bearer',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function redxConfigFor(string $environment): array
    {
        $env = $environment === 'live' ? 'live' : 'sandbox';
        $envConfig = (array) config("inventory.courier.redx.{$env}", []);
        $baseUrl = (string) ($envConfig['base_url'] ?? '');

        return [
            'sandbox'          => $env === 'sandbox',
            'base_urls'        => ['sandbox' => $baseUrl, 'live' => $baseUrl],
            'api_access_token' => $envConfig['api_access_token'] ?? '',
        ];
    }
}
