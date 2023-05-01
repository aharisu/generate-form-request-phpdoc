<?php

namespace aharisu\GenerateFormRequestPHPDoc;

use aharisu\GenerateFormRequestPHPDoc\Console\GenerateCommand;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class GenerateFormRequestPhpdocServiceProvider extends ServiceProvider implements DeferrableProvider
{
    private string $configFilename = 'generate-form-request-phpdoc.php';

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $configPath = __DIR__ . '/../config/' . $this->configFilename;
        if (function_exists('config_path')) {
            $publishPath = config_path($this->configFilename);
        } else {
            $publishPath = base_path('config/' . $this->configFilename);
        }
        $this->publishes([$configPath => $publishPath], 'config');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $configPath = __DIR__ . '/../config/' . $this->configFilename;
        $this->mergeConfigFrom($configPath, basename($this->configFilename, ".php"));

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
