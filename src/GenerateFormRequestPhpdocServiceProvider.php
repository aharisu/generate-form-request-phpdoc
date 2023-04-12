<?php

namespace aharisu\GenerateFormRequestPHPDoc;

use aharisu\GenerateFormRequestPHPDoc\Console\GenerateCommand;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class GenerateFormRequestPhpdocServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(
            'command.gen-formrequest-phpdoc.generate',
            function ($app) {
                return new GenerateCommand($app['files']);
            }
        );

        $this->commands(
            'command.gen-formrequest-phpdoc.generate',
        );
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['command.gen-formrequest-phpdoc.generate'];
    }
}
