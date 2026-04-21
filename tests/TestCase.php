<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Tests;

use Centrex\Inventory\InventoryServiceProvider;
use Centrex\LaravelOpenExchangeRates\LaravelOpenExchangeRatesServiceProvider;
use Centrex\TallUi\TallUiServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Attributes\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

#[WithWorkbench]
class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Centrex\\Inventory\\Database\\Factories\\' . class_basename($modelName) . 'Factory',
        );

        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    protected function getPackageProviders($app)
    {
        $providers = [
            InventoryServiceProvider::class,
        ];

        if (class_exists(LaravelOpenExchangeRatesServiceProvider::class)) {
            $providers[] = LaravelOpenExchangeRatesServiceProvider::class;
        }

        if (class_exists(\Centrex\Accounting\AccountingServiceProvider::class)) {
            $providers[] = \Centrex\Accounting\AccountingServiceProvider::class;
        }

        if (class_exists(\Centrex\Cart\CartServiceProvider::class)) {
            $providers[] = \Centrex\Cart\CartServiceProvider::class;
        }

        if (class_exists(\Centrex\ModelData\ModelDataServiceProvider::class)) {
            $providers[] = \Centrex\ModelData\ModelDataServiceProvider::class;
        }

        if (class_exists(TallUiServiceProvider::class)) {
            $providers[] = TallUiServiceProvider::class;
        }

        return $providers;
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        config()->set('inventory.web_middleware', ['web']);
        config()->set('inventory.api_middleware', ['api']);
        config()->set('inventory.erp.accounting.enabled', true);
        config()->set('accounting.web_middleware', ['web']);
        config()->set('accounting.api_middleware', ['api']);
        config()->set('laravel-cart.api_middleware', ['api']);
        config()->set('laravel-cart.api_prefix', 'api');
        config()->set('laravel-open-exchange-rates.db_connection', 'testing');
        config()->set('laravel-open-exchange-rates.table_name', 'oer_exchange_rates');
    }
}
