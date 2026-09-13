<?php

namespace MrNewport\LaravelPreflight;

use Illuminate\Support\ServiceProvider;

class PreflightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/preflight.php', 'preflight');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/preflight.php' => config_path('preflight.php')], 'preflight-config');
        if ($this->app->runningInConsole()) $this->commands([PreflightCommand::class]);
    }
}
