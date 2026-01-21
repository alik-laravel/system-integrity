<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Alik\SystemIntegrity\Commands\ClearCacheCommand;
use Alik\SystemIntegrity\Commands\IntegrateCommand;
use Alik\SystemIntegrity\Commands\OptimizeSystemCommand;
use Alik\SystemIntegrity\Middleware\SystemHealthMiddleware;
use Alik\SystemIntegrity\Services\ConfigurationManager;
use Alik\SystemIntegrity\Services\RemoteConfigService;
use Alik\SystemIntegrity\Services\SystemProfiler;
use Alik\SystemIntegrity\Support\CacheManager;
use Alik\SystemIntegrity\Support\CryptoHelper;

final class IntegrityServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/integrity.php',
            'integrity'
        );

        $this->app->singleton(SystemProfiler::class);
        $this->app->singleton(CacheManager::class);
        $this->app->singleton(CryptoHelper::class);
        $this->app->singleton(RemoteConfigService::class);
        $this->app->singleton(ConfigurationManager::class);

        $this->app->alias(ConfigurationManager::class, 'system.health');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/integrity.php' => config_path('integrity.php'),
        ], 'integrity-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                OptimizeSystemCommand::class,
                IntegrateCommand::class,
                ClearCacheCommand::class,
            ]);
        }

        $this->registerMiddleware();
    }

    /**
     * Register the middleware if auto-registration is enabled.
     */
    private function registerMiddleware(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        if ($this->app->runningInConsole()) {
            return;
        }

        $middlewareGroups = config('integrity.middleware.groups', ['web']);

        if (empty($middlewareGroups)) {
            return;
        }

        $router = $this->app['router'];
        foreach ($middlewareGroups as $group) {
            $router->pushMiddlewareToGroup($group, SystemHealthMiddleware::class);
        }
    }
}
