<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class DiagnosticService
{
    /**
     * Collect all system diagnostics
     */
    public static function collect(): array
    {
        return [
            'collected_at' => now()->toISOString(),
            'system' => self::getSystemInfo(),
            'network' => self::getNetworkInfo(),
            'php' => self::getPhpInfo(),
            'disk' => self::getDiskInfo(),
            'process' => self::getProcessInfo(),
        ];
    }

    /**
     * Get system information
     */
    public static function getSystemInfo(): array
    {
        return [
            'os' => PHP_OS,
            'os_family' => PHP_OS_FAMILY,
            'hostname' => gethostname(),
            'server_time' => now()->toISOString(),
            'timezone' => config('app.timezone'),
            'uptime' => self::getUptime(),
        ];
    }

    /**
     * Get network information including internet speed test
     */
    public static function getNetworkInfo(): array
    {
        $networkInfo = [
            'internet_reachable' => false,
            'bps_website_reachable' => false,
            'dns_resolution' => null,
            'latency_ms' => null,
            'download_speed_mbps' => null,
        ];

        // Check internet connectivity
        try {
            $start = microtime(true);
            $response = Http::timeout(10)->get('https://www.google.com');
            $latency = (microtime(true) - $start) * 1000;
            
            $networkInfo['internet_reachable'] = $response->successful();
            $networkInfo['latency_ms'] = round($latency, 2);
        } catch (\Exception $e) {
            $networkInfo['internet_error'] = $e->getMessage();
        }

        // Check BPS website specifically
        try {
            $start = microtime(true);
            $response = Http::timeout(30)->get('https://scp.bps.gub.uy/PortalServLineaWeb');
            $bpsLatency = (microtime(true) - $start) * 1000;
            
            $networkInfo['bps_website_reachable'] = $response->successful();
            $networkInfo['bps_latency_ms'] = round($bpsLatency, 2);
            $networkInfo['bps_status_code'] = $response->status();
        } catch (\Exception $e) {
            $networkInfo['bps_error'] = $e->getMessage();
        }

        // DNS resolution test
        try {
            $start = microtime(true);
            $ip = gethostbyname('scp.bps.gub.uy');
            $dnsTime = (microtime(true) - $start) * 1000;
            
            $networkInfo['dns_resolution'] = $ip !== 'scp.bps.gub.uy' ? $ip : 'failed';
            $networkInfo['dns_resolution_ms'] = round($dnsTime, 2);
        } catch (\Exception $e) {
            $networkInfo['dns_error'] = $e->getMessage();
        }

        // Simple download speed test (download small file and measure)
        try {
            $testUrl = 'https://www.google.com/images/branding/googlelogo/2x/googlelogo_color_272x92dp.png';
            $start = microtime(true);
            $response = Http::timeout(30)->get($testUrl);
            $duration = microtime(true) - $start;
            
            if ($response->successful()) {
                $sizeBytes = strlen($response->body());
                $speedMbps = ($sizeBytes * 8 / 1000000) / $duration;
                $networkInfo['download_speed_mbps'] = round($speedMbps, 2);
            }
        } catch (\Exception $e) {
            $networkInfo['speed_test_error'] = $e->getMessage();
        }

        return $networkInfo;
    }

    /**
     * Get PHP runtime information
     */
    public static function getPhpInfo(): array
    {
        return [
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'max_execution_time' => ini_get('max_execution_time'),
            'extensions' => [
                'mongodb' => extension_loaded('mongodb'),
                'curl' => extension_loaded('curl'),
                'openssl' => extension_loaded('openssl'),
            ],
        ];
    }

    /**
     * Get disk space information
     */
    public static function getDiskInfo(): array
    {
        $path = storage_path();
        
        return [
            'storage_path' => $path,
            'free_space_gb' => round(disk_free_space($path) / 1024 / 1024 / 1024, 2),
            'total_space_gb' => round(disk_total_space($path) / 1024 / 1024 / 1024, 2),
            'free_percentage' => round((disk_free_space($path) / disk_total_space($path)) * 100, 2),
        ];
    }

    /**
     * Get current process information
     */
    public static function getProcessInfo(): array
    {
        return [
            'pid' => getmypid(),
            'user' => get_current_user(),
            'script' => $_SERVER['SCRIPT_FILENAME'] ?? 'cli',
        ];
    }

    /**
     * Get system uptime
     */
    protected static function getUptime(): ?string
    {
        try {
            if (PHP_OS_FAMILY === 'Linux') {
                $uptime = file_get_contents('/proc/uptime');
                $seconds = (int) explode(' ', $uptime)[0];
                return self::formatUptime($seconds);
            } elseif (PHP_OS_FAMILY === 'Darwin') {
                $result = Process::run('uptime');
                return trim($result->output());
            }
        } catch (\Exception $e) {
            return null;
        }
        
        return null;
    }

    /**
     * Format uptime seconds to human readable
     */
    protected static function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        return "{$days}d {$hours}h {$minutes}m";
    }

    /**
     * Categorize error based on message and context
     */
    public static function categorizeError(\Throwable $e, ?string $step = null): array
    {
        $message = strtolower($e->getMessage());
        
        $errorType = 'unknown';
        $isRetryable = true;
        
        // Timeout errors
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            $errorType = 'timeout';
            $isRetryable = true;
        }
        // Network errors
        elseif (str_contains($message, 'connection') || 
                str_contains($message, 'network') || 
                str_contains($message, 'dns') ||
                str_contains($message, 'could not resolve')) {
            $errorType = 'network';
            $isRetryable = true;
        }
        // Website down
        elseif (str_contains($message, '503') || 
                str_contains($message, '502') || 
                str_contains($message, '500') ||
                str_contains($message, 'service unavailable') ||
                str_contains($message, 'bad gateway')) {
            $errorType = 'website_down';
            $isRetryable = true;
        }
        // Authentication errors
        elseif (str_contains($message, 'auth') || 
                str_contains($message, 'login') || 
                str_contains($message, 'credential') ||
                str_contains($message, '401') ||
                str_contains($message, '403')) {
            $errorType = 'authentication';
            $isRetryable = false; // Don't retry auth errors
        }
        // Element not found (UI changed or slow loading)
        elseif (str_contains($message, 'not found') || 
                str_contains($message, 'no element') ||
                str_contains($message, 'selector') ||
                str_contains($message, 'frame')) {
            $errorType = 'element_not_found';
            $isRetryable = true;
        }
        // Validation errors
        elseif (str_contains($message, 'valid') || str_contains($message, 'invalid')) {
            $errorType = 'validation';
            $isRetryable = false;
        }

        return [
            'type' => $errorType,
            'is_retryable' => $isRetryable,
            'message' => $e->getMessage(),
            'step' => $step,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
    }
}

