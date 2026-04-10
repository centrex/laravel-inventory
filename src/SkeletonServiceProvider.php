<?php

namespace VendorName\Skeleton;

use Illuminate\Support\ServiceProvider;

class SkeletonServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        /*
         * Optional methods to load your package assets
         */
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', ':package_slug');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', ':package_slug');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/config.php' => config_path(':package_slug.php'),
            ], ':package_slug-config');

            // Publishing the migrations.
            /*$this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations')
            ], ':package_slug-migrations');*/

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/:package_slug'),
            ], ':package_slug-views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/:package_slug'),
            ], ':package_slug-assets');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/:package_slug'),
            ], ':package_slug-lang');*/

            // Registering package commands.
            // $this->commands([]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', ':package_slug');

        // Register the main class to use with the facade
        $this->app->singleton(':package_slug', function () {
            return new Skeleton;
        });
    }
}