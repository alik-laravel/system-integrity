<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Commands;

use Illuminate\Console\Command;
use Alik\SystemIntegrity\Support\CacheManager;

/**
 * Command to clear the validation cache.
 */
final class ClearCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:clear-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the system configuration validation cache';

    public function __construct(
        private readonly CacheManager $cache
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->cache->flush();

        $this->info('Validation cache cleared successfully.');

        return self::SUCCESS;
    }
}
