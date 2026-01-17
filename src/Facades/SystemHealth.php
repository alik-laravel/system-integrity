<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Facades;

use Illuminate\Support\Facades\Facade;
use Alik\SystemIntegrity\Services\ConfigurationManager;

/**
 * @method static bool verify()
 * @method static bool isConfigured()
 * @method static array|null getConfigurationData()
 *
 * @see \Alik\SystemIntegrity\Services\ConfigurationManager
 */
final class SystemHealth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ConfigurationManager::class;
    }
}
