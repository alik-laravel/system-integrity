<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Commands;

use Illuminate\Console\Command;
use Alik\SystemIntegrity\Services\RemoteConfigService;
use Alik\SystemIntegrity\Services\SystemProfiler;
use Alik\SystemIntegrity\Support\CacheManager;

/**
 * Command to activate and optimize system configuration.
 */
final class OptimizeSystemCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:activate
                            {--key= : API key for configuration service}
                            {--show-info : Show system information without activating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Activate and optimize system configuration';

    public function __construct(
        private readonly RemoteConfigService $remoteService,
        private readonly SystemProfiler $profiler,
        private readonly CacheManager $cache
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('show-info')) {
            return $this->showSystemInfo();
        }

        $apiKey = $this->option('key');
        if (empty($apiKey)) {
            $apiKey = $this->ask('Enter API key');
        }

        if (empty($apiKey)) {
            $this->error('API key is required');

            return self::FAILURE;
        }

        $this->info('Collecting system information...');
        $this->newLine();

        $this->table(
            ['Property', 'Value'],
            [
                ['Device Hash', substr($this->profiler->getSystemSignature(), 0, 16) . '...'],
                ['Hostname', gethostname() ?: 'unknown'],
                ['PHP Version', PHP_VERSION],
                ['OS', PHP_OS_FAMILY],
            ]
        );

        $this->newLine();
        $this->info('Activating configuration...');

        $result = $this->remoteService->activateConfiguration($apiKey);

        if (! $result['success']) {
            $this->error('Activation failed: ' . ($result['error'] ?? 'Unknown error'));

            return self::FAILURE;
        }

        $cachePath = config('integrity.system_cache_path');
        $cacheDir = dirname($cachePath);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        if (file_put_contents($cachePath, $result['data']) === false) {
            $this->error('Failed to save configuration file');

            return self::FAILURE;
        }

        $this->cache->flush();

        $this->newLine();
        $this->info('System configuration activated successfully!');
        $this->line('Configuration saved to: ' . $cachePath);
        $this->line('Validation cache cleared.');

        return self::SUCCESS;
    }

    /**
     * Display system information.
     */
    private function showSystemInfo(): int
    {
        $this->info('System Information');
        $this->newLine();

        $metadata = $this->profiler->collectMetadata();

        $this->table(
            ['Property', 'Value'],
            [
                ['Device Hash', $this->profiler->getSystemSignature()],
                ['Hostname', $metadata['hostname']],
                ['OS', $metadata['os']],
                ['OS Version', $metadata['os_version']],
                ['PHP Version', $metadata['php_version']],
                ['Laravel Version', $metadata['laravel_version']],
                ['Server Software', $metadata['server_software']],
            ]
        );

        return self::SUCCESS;
    }
}
