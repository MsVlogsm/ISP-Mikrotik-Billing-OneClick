<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Providers;

use Illuminate\Support\ServiceProvider;
use XLink\SmsBridge\SmsBridgeManager;

class SmsBridgeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/../../config/sms-bridge.php', 'sms-bridge'
        );

        // Register the main manager singleton
        $this->app->singleton('sms-bridge', function ($app) {
            return new SmsBridgeManager($app);
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Load custom package routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Load HTML blade views
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'smsBridge');

        // Load database migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // Publish config
        $this->publishes([
            __DIR__.'/../../config/sms-bridge.php' => config_path('sms-bridge.php'),
        ], 'sms-bridge-config');

        // Publish views
        $this->publishes([
            __DIR__.'/../../resources/views' => resource_path('views/vendor/smsBridge'),
        ], 'sms-bridge-views');
    }
}
