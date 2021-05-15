<?php

namespace Montanabay39\Mpesa;

use Illuminate\Support\ServiceProvider;

class MpesaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Load the migration files.
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        // Set a group namespace for the routes defined, then load the route file.
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');

        // Publishing the configuration & certificate files.
        $this->publishes([
            __DIR__.'/config/mpesa.php' => config_path('mpesa.php'),
            __DIR__.'/public/certificates/' => public_path('vendor/mpesa/certificates/')
        ]);

        // merge with config from mpesa.php
        $this->mergeConfigFrom(__DIR__.'/config/mpesa.php', 'mpesa');
    }
}
