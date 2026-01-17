<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Alik\SystemIntegrity\Services\ConfigurationManager;

/**
 * Middleware to verify system health before processing requests.
 */
final class SystemHealthMiddleware
{
    public function __construct(
        private readonly ConfigurationManager $manager
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('integrity.verification.enabled', true)) {
            return $next($request);
        }

        if ($this->isExcludedPath($request->path())) {
            return $next($request);
        }

        if (! $this->manager->verify()) {
            abort(500, 'System configuration error');
        }

        return $next($request);
    }

    /**
     * Check if the path is excluded from verification.
     */
    private function isExcludedPath(string $path): bool
    {
        $excludedPaths = config('integrity.middleware.exclude_paths', []);

        foreach ($excludedPaths as $excluded) {
            if (str_starts_with($path, $excluded) || $path === $excluded) {
                return true;
            }
        }

        return false;
    }
}
