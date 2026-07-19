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
     *                                        weight_kg, cod_amount, item_description,
     *                                        (pathao only) recipient_city, recipient_zone, recipient_area,
     *                                        (redx only) delivery_area_id, delivery_area,
     *                                        pickup_store_id
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

        $payload = [
            'store_id'          => $config['store_id'],
            'recipient_name'    => (string) $fields['recipient_name'],
            'recipient_phone'   => (string) $fields['recipient_phone'],
            'recipient_address' => (string) $fields['recipient_address'],
            // Required by Pathao's own API validation alongside the address — omitting these
            // is what causes a 422 from aladdin/api/v1/orders even though every other field
            // this package used to send was present and well-formed.
            'recipient_city'    => (int) $fields['recipient_city'],
            'recipient_zone'    => (int) $fields['recipient_zone'],
            'delivery_type'     => 48, // Normal delivery
            'item_type'         => 2,  // Parcel
            'item_quantity'     => 1,
            'item_weight'       => (float) $fields['weight_kg'],
            'item_description'  => (string) ($fields['item_description'] ?? $saleOrder->so_number),
            'amount_to_collect' => (float) $fields['cod_amount'],
            'merchant_order_id' => $saleOrder->so_number,
        ];

        if (filled($fields['recipient_area'] ?? null)) {
            $payload['recipient_area'] = (int) $fields['recipient_area'];
        }

        $response = $service->createOrder($payload);

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
        $deliveryAreaId = (int) $fields['delivery_area_id'];

        // Redx requires the area *name* alongside its id; look it up when the
        // caller didn't supply one so the API doesn't reject the parcel.
        $deliveryArea = (string) ($fields['delivery_area'] ?? '');

        if ($deliveryArea === '') {
            $areaResponse = $service->getAreaById($deliveryAreaId);
            $deliveryArea = (string) (data_get($areaResponse, 'areas.0.name')
                ?? data_get($areaResponse, 'area.name')
                ?? '');
        }

        $payload = [
            'customer_name'          => (string) $fields['recipient_name'],
            'customer_phone'         => (string) $fields['recipient_phone'],
            'delivery_area'          => $deliveryArea,
            'delivery_area_id'       => $deliveryAreaId,
            'customer_address'       => (string) $fields['recipient_address'],
            'merchant_invoice_id'    => $saleOrder->so_number,
            'cash_collection_amount' => $codAmount,
            'parcel_weight'          => (int) round(((float) $fields['weight_kg']) * 1000), // kg -> grams
            'value'                  => $codAmount,
            'parcel_details_json'    => [
                ['name' => $itemDescription, 'category' => 'Others', 'value' => $codAmount],
            ],
        ];

        if (filled($fields['pickup_store_id'] ?? null)) {
            $payload['pickup_store_id'] = (int) $fields['pickup_store_id'];
        }

        $response = $service->createParcel($payload);

        return [
            'tracking_number' => (string) ($response['tracking_id'] ?? ''),
            'raw'             => $response,
        ];
    }

    /**
     * Live parcel info + tracking history from the courier API for a booked parcel.
     *
     * @return array{info: array<string, mixed>, tracking: array<int, array<string, mixed>>}
     */
    public function parcelDetails(string $provider, string $environment, string $trackingNumber, ?string $phone = null): array
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('Courier integration is not enabled.');
        }

        return match ($provider) {
            'redx'   => $this->redxParcelDetails($environment, $trackingNumber),
            'pathao' => $this->pathaoParcelDetails($environment, $trackingNumber, $phone),
            default  => throw new \InvalidArgumentException("Unsupported courier provider [{$provider}]."),
        };
    }

    /** @return array{info: array<string, mixed>, tracking: array<int, array<string, mixed>>} */
    private function redxParcelDetails(string $environment, string $trackingNumber): array
    {
        $service = new RedxService(app(HttpFactory::class), ['redx' => $this->redxConfigFor($environment)]);

        $info = (array) (data_get($service->parcelInfo($trackingNumber), 'parcel') ?? []);
        $tracking = (array) (data_get($service->track($trackingNumber), 'tracking') ?? []);

        return ['info' => $info, 'tracking' => array_values($tracking)];
    }

    /** @return array{info: array<string, mixed>, tracking: array<int, array<string, mixed>>} */
    private function pathaoParcelDetails(string $environment, string $trackingNumber, ?string $phone): array
    {
        $service = new PathaoService(app(HttpFactory::class), ['pathao' => $this->pathaoConfigFor($environment)]);

        $info = (array) (data_get($service->orderInfo($trackingNumber), 'data') ?? []);

        $tracking = [];

        if (filled($phone)) {
            try {
                $trackResponse = $service->track($trackingNumber, (string) $phone);
                $tracking = (array) (data_get($trackResponse, 'data.log')
                    ?? data_get($trackResponse, 'data.tracking')
                    ?? data_get($trackResponse, 'data')
                    ?? []);
            } catch (\Throwable) {
                // Pathao's tracking endpoint is separate from the merchant API —
                // still show the order info when only tracking is unavailable.
            }
        }

        return ['info' => $info, 'tracking' => array_values($tracking)];
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

    /**
     * Pathao cities for the given environment — the first step of the
     * city → zone → area cascade required by recipient_city on order creation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pathaoCities(string $environment): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $service = new PathaoService(app(HttpFactory::class), ['pathao' => $this->pathaoConfigFor($environment)]);

        return array_values($service->cities());
    }

    /** @return array<int, array<string, mixed>> */
    public function pathaoZones(string $environment, int $cityId): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $service = new PathaoService(app(HttpFactory::class), ['pathao' => $this->pathaoConfigFor($environment)]);

        return array_values($service->zones($cityId));
    }

    /** @return array<int, array<string, mixed>> */
    public function pathaoAreas(string $environment, int $zoneId): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $service = new PathaoService(app(HttpFactory::class), ['pathao' => $this->pathaoConfigFor($environment)]);

        return array_values($service->areas($zoneId));
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
            // Constructor config replaces the whole pathao key — carry the package's
            // tracking endpoint over so PathaoService::track() keeps working.
            'tracking_url' => (string) config('courier.pathao.tracking_url', 'https://merchant.pathao.com/api/v1/user/tracking'),
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
