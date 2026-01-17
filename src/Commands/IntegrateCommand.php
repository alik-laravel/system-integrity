<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Command to integrate system integrity checks into the application.
 */
final class IntegrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:integrate
                            {--rollback : Rollback integration changes}
                            {--dry-run : Show changes without applying them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Integrate system integrity checks into the application';

    /**
     * @var array<string, string>
     */
    private array $backups = [];

    /**
     * @var array<string>
     */
    private array $changes = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $this->info('Integrating system integrity checks...');
        $this->newLine();

        $dryRun = (bool) $this->option('dry-run');

        $this->integrateMiddleware($dryRun);
        $this->integrateBaseModel($dryRun);
        $this->integrateBaseController($dryRun);
        $this->updateGitignore($dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run complete. No changes were made.');
            $this->line('Run without --dry-run to apply changes.');
        } else {
            $this->saveIntegrationLog();
            $this->newLine();
            $this->info('Integration complete!');
            $this->line('Run "php artisan system:activate --key=YOUR_API_KEY" to activate.');
        }

        return self::SUCCESS;
    }

    /**
     * Integrate middleware into bootstrap/app.php.
     */
    private function integrateMiddleware(bool $dryRun): void
    {
        $bootstrapPath = base_path('bootstrap/app.php');

        if (! File::exists($bootstrapPath)) {
            $this->warn('bootstrap/app.php not found, skipping middleware integration');

            return;
        }

        $content = File::get($bootstrapPath);

        $middlewareUse = 'use Alik\\SystemIntegrity\\Middleware\\SystemHealthMiddleware;';
        $middlewareClass = 'SystemHealthMiddleware::class';

        if (str_contains($content, 'SystemHealthMiddleware')) {
            $this->line('  [SKIP] Middleware already integrated');

            return;
        }

        $newContent = $content;
        $modified = false;

        // Add use statement if not present
        if (! str_contains($content, $middlewareUse)) {
            $newContent = preg_replace(
                '/(use Illuminate\\\\Foundation\\\\Application;)/',
                "$1\n{$middlewareUse}",
                $newContent
            );
            $modified = true;
        }

        // Pattern 1: $middleware->web(append: [...]) - add to array
        if (preg_match('/(\$middleware->web\s*\(\s*append:\s*\[)([^\]]*?)(\]\s*\)\s*;)/s', $newContent, $matches)) {
            $existingAppend = $matches[2];
            $newAppend = rtrim($existingAppend);
            if (! empty(trim($newAppend))) {
                $newAppend .= ",\n            {$middlewareClass},";
            } else {
                $newAppend = "\n            {$middlewareClass},\n        ";
            }
            $newContent = str_replace(
                $matches[0],
                $matches[1] . $newAppend . $matches[3],
                $newContent
            );
            $modified = true;
        }
        // Pattern 2: withMiddleware callback without web append - add new line
        elseif (preg_match('/withMiddleware\s*\(\s*function\s*\(\s*Middleware\s*\$middleware\s*\).*?\{/s', $newContent)) {
            $newContent = preg_replace(
                '/(withMiddleware\s*\(\s*function\s*\(\s*Middleware\s*\$middleware\s*\).*?\{)/s',
                "$1\n        \$middleware->append({$middlewareClass});",
                $newContent
            );
            $modified = true;
        }

        if ($modified && $newContent !== $content) {
            $this->changes[] = "Added SystemHealthMiddleware to web middleware group";

            if ($dryRun) {
                $this->line('  [DRY-RUN] Would add middleware to bootstrap/app.php');
            } else {
                $this->backups[$bootstrapPath] = $content;
                File::put($bootstrapPath, $newContent);
                $this->line('  [OK] Added middleware to bootstrap/app.php');
            }
        } elseif (! $modified) {
            $this->warn('  [WARN] Could not find suitable middleware configuration in bootstrap/app.php');
        }
    }

    /**
     * Integrate trait into base Model class.
     */
    private function integrateBaseModel(bool $dryRun): void
    {
        $modelPath = app_path('Models/BaseModel.php');

        if (! File::exists($modelPath)) {
            $this->line('  [SKIP] Base Model class not found at app/Models/BaseModel.php');

            return;
        }

        $content = File::get($modelPath);

        if (str_contains($content, 'RequiresOptimization')) {
            $this->line('  [SKIP] RequiresOptimization trait already integrated in Model');

            return;
        }

        $traitUse = 'use Alik\\SystemIntegrity\\Traits\\RequiresOptimization;';
        $traitUsage = 'use RequiresOptimization;';

        $newContent = $content;

        // Add use statement if not present (handle both old and new namespace)
        if (! str_contains($content, 'Alik\\SystemIntegrity\\Traits\\RequiresOptimization')) {
            // Remove old namespace if present
            $newContent = str_replace(
                'use Vendor\\SystemIntegrity\\Traits\\RequiresOptimization;',
                '',
                $newContent
            );
            $newContent = preg_replace(
                '/(namespace App\\\\Models;)/',
                "$1\n\n{$traitUse}",
                $newContent
            );
        }

        // Add trait usage inside class if not present
        if (! str_contains($content, $traitUsage)) {
            // Match class declaration (both abstract and non-abstract, BaseModel or Model)
            $newContent = preg_replace(
                '/((?:abstract\s+)?class\s+(?:BaseModel|Model)\s+extends\s+[^\{]+\{)/',
                "$1\n    {$traitUsage}\n",
                $newContent
            );
        }

        if ($newContent !== $content) {
            $this->changes[] = "Added RequiresOptimization trait to base Model";

            if ($dryRun) {
                $this->line('  [DRY-RUN] Would add trait to app/Models/BaseModel.php');
            } else {
                $this->backups[$modelPath] = $content;
                File::put($modelPath, $newContent);
                $this->line('  [OK] Added trait to app/Models/BaseModel.php');
            }
        }
    }

    /**
     * Integrate verification into base Controller class.
     */
    private function integrateBaseController(bool $dryRun): void
    {
        $controllerPath = app_path('Http/Controllers/BaseController.php');

        if (! File::exists($controllerPath)) {
            $this->line('  [SKIP] Base Controller not found');

            return;
        }

        $content = File::get($controllerPath);

        if (str_contains($content, 'SystemHealth::verify')) {
            $this->line('  [SKIP] SystemHealth already integrated in Controller');

            return;
        }

        $facadeUse = 'use Alik\\SystemIntegrity\\Facades\\SystemHealth;';
        $constructorCode = <<<'CODE'

    public function __construct()
    {
        SystemHealth::verify();
    }
CODE;

        $newContent = $content;

        // Add use statement if not present (handle both old and new namespace)
        if (! str_contains($content, 'Alik\\SystemIntegrity\\Facades\\SystemHealth')) {
            // Remove old namespace if present
            $newContent = str_replace(
                'use Vendor\\SystemIntegrity\\Facades\\SystemHealth;',
                '',
                $newContent
            );
            $newContent = preg_replace(
                '/(namespace App\\\\Http\\\\Controllers;)/',
                "$1\n\n{$facadeUse}",
                $newContent
            );
        }

        // Add constructor or modify existing one
        if (str_contains($content, 'public function __construct')) {
            if (! str_contains($content, 'SystemHealth::verify()')) {
                $newContent = preg_replace(
                    '/(public function __construct\s*\([^)]*\)\s*\{)/',
                    "$1\n        SystemHealth::verify();",
                    $newContent
                );
            }
        } else {
            // Match class declaration (both abstract and non-abstract, BaseController or Controller)
            $newContent = preg_replace(
                '/((?:abstract\s+)?class\s+(?:BaseController|Controller)(?:\s+extends\s+\S+)?\s*\{)/',
                "$1{$constructorCode}",
                $newContent
            );
        }

        if ($newContent !== $content) {
            $this->changes[] = "Added SystemHealth::verify() to base Controller";

            if ($dryRun) {
                $this->line('  [DRY-RUN] Would modify app/Http/Controllers/BaseController.php');
            } else {
                $this->backups[$controllerPath] = $content;
                File::put($controllerPath, $newContent);
                $this->line('  [OK] Modified app/Http/Controllers/BaseController.php');
            }
        }
    }

    /**
     * Update .gitignore to exclude the system cache file.
     */
    private function updateGitignore(bool $dryRun): void
    {
        $gitignorePath = base_path('.gitignore');
        $cacheFile = config('integrity.system_cache_path', storage_path('app/.system_cache'));
        $relativePath = str_replace(base_path() . '/', '', $cacheFile);

        if (! File::exists($gitignorePath)) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Would create .gitignore with {$relativePath}");
            } else {
                File::put($gitignorePath, $relativePath . "\n");
                $this->line("  [OK] Created .gitignore with {$relativePath}");
            }

            return;
        }

        $content = File::get($gitignorePath);

        if (str_contains($content, $relativePath)) {
            $this->line('  [SKIP] .gitignore already contains cache path');

            return;
        }

        $this->changes[] = "Added {$relativePath} to .gitignore";

        if ($dryRun) {
            $this->line("  [DRY-RUN] Would add {$relativePath} to .gitignore");
        } else {
            $newContent = rtrim($content) . "\n\n# System integrity cache\n{$relativePath}\n";
            File::put($gitignorePath, $newContent);
            $this->line("  [OK] Added {$relativePath} to .gitignore");
        }
    }

    /**
     * Save integration log for rollback.
     */
    private function saveIntegrationLog(): void
    {
        $logPath = storage_path('app/integrity_integration.json');
        $log = [
            'timestamp' => now()->toIso8601String(),
            'backups' => $this->backups,
            'changes' => $this->changes,
        ];

        File::put($logPath, json_encode($log, JSON_PRETTY_PRINT));
    }

    /**
     * Rollback integration changes.
     */
    private function rollback(): int
    {
        $logPath = storage_path('app/integrity_integration.json');

        if (! File::exists($logPath)) {
            $this->error('No integration log found. Cannot rollback.');

            return self::FAILURE;
        }

        $log = json_decode(File::get($logPath), true);

        if (empty($log['backups'])) {
            $this->info('No backups found in integration log.');

            return self::SUCCESS;
        }

        $this->info('Rolling back integration changes...');
        $this->newLine();

        foreach ($log['backups'] as $path => $content) {
            if (File::exists($path)) {
                File::put($path, $content);
                $this->line("  [OK] Restored {$path}");
            }
        }

        File::delete($logPath);

        $this->newLine();
        $this->info('Rollback complete!');

        return self::SUCCESS;
    }
}
