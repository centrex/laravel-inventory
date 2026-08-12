<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Centrex\Inventory\InventoryServiceProvider;
use Centrex\LaravelOpenExchangeRates\LaravelOpenExchangeRatesServiceProvider;
use Centrex\TallUi\TallUiServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
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

        // Spatie's media table migration is shipped as a stub meant for `vendor:publish`, not
        // auto-discovered — without it, any model using HasPrimaryImage (Product, Customer, ...)
        // 500s the moment it's actually serialized (its 'primary_image_url' append queries this
        // table). A real app publishes this via composer/artisan; the package test suite has to
        // load it itself.
        $mediaMigrationStub = __DIR__ . '/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';

        if (file_exists($mediaMigrationStub) && !\Illuminate\Support\Facades\Schema::hasTable('media')) {
            (require $mediaMigrationStub)->up();
        }
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
            $providers[] = LivewireServiceProvider::class;
            $providers[] = TallUiServiceProvider::class;
        }

        // Required by <x-tallui-icon> (and every tallui component that renders one) —
        // without these, any full-page render of a view using icons throws either an
        // unresolvable IconsManifest dependency or an "Svg ... not found" error.
        if (class_exists(BladeIconsServiceProvider::class)) {
            $providers[] = BladeIconsServiceProvider::class;
        }

        if (class_exists(BladeHeroiconsServiceProvider::class)) {
            $providers[] = BladeHeroiconsServiceProvider::class;
        }

        // Product/Customer/etc. use HasPrimaryImage (Spatie InteractsWithMedia) — without this,
        // any test that fully serializes one of those models (e.g. a raw JSON API response
        // rather than a Resource that hand-picks fields) hits getMediaModel() reading an
        // unregistered 'media-library.media_model' config key and throws a TypeError. A real
        // app has this auto-discovered via composer, so it's a test-harness gap, not a
        // production one.
        if (class_exists(\Spatie\MediaLibrary\MediaLibraryServiceProvider::class)) {
            $providers[] = \Spatie\MediaLibrary\MediaLibraryServiceProvider::class;
        }

        return $providers;
    }

    public function getEnvironmentSetUp($app)
    {
        // Laravel's own framework default (vendor/laravel/framework/config/queue.php) is
        // 'database', not 'sync' — and this package's test schema has no `jobs` table.
        // Any ShouldQueue job/listener touched during a test (e.g. accounting's
        // SyncCustomerOutstanding, fired via Accounting::postInvoice()) would otherwise try
        // to INSERT into a nonexistent table instead of just running inline.
        config()->set('queue.default', 'sync');

        // Livewire signs component snapshots with the app key — needed by any test that does a
        // full Livewire::test(...) round-trip (mount + render + update) rather than instantiating
        // the component class directly, or rendering throws MissingAppKeyException.
        config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

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
