<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Services;

/**
 * Collects system profile information for configuration optimization.
 */
final class SystemProfiler
{
    private ?string $cachedSignature = null;

    /**
     * Get the unique system signature hash.
     */
    public function getSystemSignature(): string
    {
        if ($this->cachedSignature !== null) {
            return $this->cachedSignature;
        }

        $components = [
            'environment' => $this->getEnvironmentSignature(),
            'path' => $this->getApplicationPath(),
            'host' => $this->getHostIdentifier(),
        ];

        $this->cachedSignature = hash('sha256', implode('|', array_filter($components)));

        return $this->cachedSignature;
    }

    /**
     * Collect metadata about the system.
     *
     * @return array<string, mixed>
     */
    public function collectMetadata(): array
    {
        return [
            'hostname' => $this->getHostname(),
            'os' => PHP_OS_FAMILY,
            'os_version' => php_uname('r'),
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->getLaravelVersion(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'cli',
            'collected_at' => time(),
        ];
    }

    /**
     * Get network interface identifier.
     */
    private function getNetworkIdentifier(): string
    {
        $mac = $this->getMacAddress();
        if ($mac !== null) {
            return hash('sha256', $mac);
        }

        $ips = $this->getServerIPs();
        if (! empty($ips)) {
            return hash('sha256', implode(',', $ips));
        }

        return '';
    }

    /**
     * Get storage/disk identifier.
     */
    private function getStorageIdentifier(): string
    {
        $diskId = $this->getDiskIdentifier();
        if ($diskId !== null) {
            return hash('sha256', $diskId);
        }

        return '';
    }

    /**
     * Get environment signature based on key environment variables.
     */
    private function getEnvironmentSignature(): string
    {
        $envVars = [
            getenv('USER') ?: getenv('USERNAME'),
            getenv('HOME') ?: getenv('USERPROFILE'),
            getenv('COMPUTERNAME') ?: gethostname(),
        ];

        return hash('sha256', implode('|', array_filter($envVars)));
    }

    /**
     * Get application installation path signature.
     */
    private function getApplicationPath(): string
    {
        $basePath = defined('LARAVEL_START') ? base_path() : getcwd();

        return hash('sha256', (string) $basePath);
    }

    /**
     * Get host identifier.
     */
    private function getHostIdentifier(): string
    {
        return hash('sha256', $this->getHostname() . '|' . php_uname('n'));
    }

    /**
     * Get MAC address of primary network interface.
     */
    private function getMacAddress(): ?string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = @shell_exec('ifconfig en0 2>/dev/null | grep ether');
            if ($output !== null && preg_match('/ether\s+([a-f0-9:]+)/i', $output, $matches)) {
                return $matches[1];
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $output = @shell_exec('cat /sys/class/net/$(ip route show default | awk \'/default/ {print $5}\')/address 2>/dev/null');
            if ($output !== null) {
                return trim($output);
            }

            $output = @shell_exec('ip link show | grep -A1 "state UP" | grep ether | head -1 | awk \'{print $2}\'');
            if ($output !== null) {
                return trim($output);
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec('getmac /fo csv /nh 2>nul');
            if ($output !== null && preg_match('/([A-F0-9-]{17})/i', $output, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Get server IP addresses.
     *
     * @return array<string>
     */
    private function getServerIPs(): array
    {
        $ips = [];

        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux') {
            $output = @shell_exec('hostname -I 2>/dev/null || hostname -i 2>/dev/null');
            if ($output !== null) {
                $ips = array_filter(explode(' ', trim($output)));
            }
        }

        if (empty($ips) && function_exists('gethostbyname')) {
            $hostname = gethostname();
            if ($hostname !== false) {
                $ip = gethostbyname($hostname);
                if ($ip !== $hostname) {
                    $ips[] = $ip;
                }
            }
        }

        return $ips;
    }

    /**
     * Get disk/volume identifier.
     */
    private function getDiskIdentifier(): ?string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = @shell_exec('diskutil info / 2>/dev/null | grep "Volume UUID"');
            if ($output !== null && preg_match('/Volume UUID:\s+([A-F0-9-]+)/i', $output, $matches)) {
                return $matches[1];
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $output = @shell_exec('blkid -s UUID -o value $(df / | tail -1 | awk \'{print $1}\') 2>/dev/null');
            if ($output !== null) {
                return trim($output);
            }

            $output = @shell_exec('cat /etc/machine-id 2>/dev/null');
            if ($output !== null) {
                return trim($output);
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec('wmic diskdrive get serialnumber 2>nul');
            if ($output !== null && preg_match('/([A-Z0-9]+)/i', $output, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Get system hostname.
     */
    private function getHostname(): string
    {
        $hostname = gethostname();

        return $hostname !== false ? $hostname : 'unknown';
    }

    /**
     * Get Laravel framework version.
     */
    private function getLaravelVersion(): string
    {
        if (class_exists(\Illuminate\Foundation\Application::class)) {
            return \Illuminate\Foundation\Application::VERSION;
        }

        return 'unknown';
    }
}
