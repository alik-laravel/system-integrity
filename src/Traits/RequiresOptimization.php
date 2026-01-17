<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Traits;

use Alik\SystemIntegrity\Facades\SystemHealth;

/**
 * Trait to require system optimization for model operations.
 */
trait RequiresOptimization
{
    /**
     * Boot the trait.
     */
    public static function bootRequiresOptimization(): void
    {
        static::creating(function ($model): void {
            SystemHealth::verify();
        });

        static::updating(function ($model): void {
            SystemHealth::verify();
        });

        static::deleting(function ($model): void {
            SystemHealth::verify();
        });
    }
}
