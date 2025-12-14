<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use Spatie\Dropbox\Client;
use Spatie\FlysystemDropbox\DropboxAdapter;

class DropboxService
{
    // Cache key for the access token
    private const CACHE_KEY = 'dropbox_access_token';
    
    // Max retries for token refresh
    private const MAX_REFRESH_RETRIES = 3;
    
    // Retry delay in seconds
    private const RETRY_DELAY_SECONDS = 5;

    /**
     * Get a FilesystemAdapter for Dropbox with auto-retry on auth failures
     */
    public static function getDisk(): FilesystemAdapter
    {
        $accessToken = self::getAccessToken();
        $client = new Client($accessToken);
        $adapter = new DropboxAdapter($client);
        $filesystem = new Filesystem($adapter);

        return new ResilientDropboxAdapter($filesystem, $adapter);
    }

    /**
     * Get a valid access token, refreshing if necessary
     */
    public static function getAccessToken(): string
    {
        $token = Cache::get(self::CACHE_KEY);

        // If we have a cached token, validate it
        if ($token) {
            $validationResult = self::validateToken($token);
            
            if ($validationResult['valid']) {
                return $token;
            }
            
            Log::warning('🔑 Dropbox token inválido o expirado, refrescando...', [
                'reason' => $validationResult['reason'],
            ]);
            
            // Clear the invalid token
            self::clearCachedToken();
        }

        return self::refreshTokenWithRetry();
    }

    /**
     * Force refresh the token (useful when operations fail with auth errors)
     */
    public static function forceRefreshToken(): string
    {
        Log::info('🔄 Forzando refresh del token de Dropbox');
        self::clearCachedToken();
        return self::refreshTokenWithRetry();
    }

    /**
     * Clear the cached token
     */
    public static function clearCachedToken(): void
    {
        Cache::forget(self::CACHE_KEY);
        Log::debug('🗑️ Token de Dropbox eliminado del cache');
    }

    /**
     * Refresh token with retry logic
     */
    protected static function refreshTokenWithRetry(): string
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= self::MAX_REFRESH_RETRIES; $attempt++) {
            try {
                Log::info("🔑 Intentando refrescar token de Dropbox (intento {$attempt}/" . self::MAX_REFRESH_RETRIES . ")");
                
                $token = self::refreshToken();
                
                Log::info('✅ Token de Dropbox refrescado exitosamente');
                return $token;
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                Log::warning("⚠️ Falló refresh de token Dropbox (intento {$attempt})", [
                    'error' => $e->getMessage(),
                ]);
                
                if ($attempt < self::MAX_REFRESH_RETRIES) {
                    $delay = self::RETRY_DELAY_SECONDS * $attempt; // Exponential backoff
                    Log::info("⏳ Esperando {$delay} segundos antes del siguiente intento...");
                    sleep($delay);
                }
            }
        }
        
        Log::error('❌ No se pudo refrescar el token de Dropbox después de ' . self::MAX_REFRESH_RETRIES . ' intentos', [
            'last_error' => $lastException?->getMessage(),
        ]);
        
        throw new DropboxAuthException(
            'Failed to refresh Dropbox token after ' . self::MAX_REFRESH_RETRIES . ' attempts: ' . $lastException?->getMessage(),
            previous: $lastException
        );
    }

    /**
     * Refresh the access token using the refresh token
     */
    protected static function refreshToken(): string
    {
        $refreshToken = env('DROPBOX_REFRESH_TOKEN');
        $clientId = env('DROPBOX_CLIENT_ID');
        $clientSecret = env('DROPBOX_CLIENT_SECRET');
        
        if (empty($refreshToken) || empty($clientId) || empty($clientSecret)) {
            throw new DropboxAuthException('Missing Dropbox credentials in .env (DROPBOX_REFRESH_TOKEN, DROPBOX_CLIENT_ID, DROPBOX_CLIENT_SECRET)');
        }

        $response = Http::timeout(30)
            ->retry(2, 1000)
            ->asForm()
            ->post('https://api.dropboxapi.com/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if ($response->failed()) {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error_description'] ?? $errorBody['error'] ?? 'Unknown error';
            
            throw new DropboxAuthException(
                "Dropbox token refresh failed: {$errorMessage} (HTTP {$response->status()})"
            );
        }

        $data = $response->json();
        $newToken = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? 14400; // Default 4 hours
        
        if (empty($newToken)) {
            throw new DropboxAuthException('Dropbox token refresh response missing access_token');
        }

        // Cache the token with a small buffer before expiry (5 minutes less)
        $cacheFor = max($expiresIn - 300, 60);
        Cache::put(self::CACHE_KEY, $newToken, now()->addSeconds($cacheFor));
        
        Log::debug('🔐 Token de Dropbox almacenado en cache', [
            'expires_in' => $expiresIn,
            'cached_for' => $cacheFor,
        ]);

        return $newToken;
    }

    /**
     * Validate if a token is still valid
     */
    protected static function validateToken(string $token): array
    {
        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->post('https://api.dropboxapi.com/2/users/get_current_account');

            if ($response->ok()) {
                return ['valid' => true, 'reason' => null];
            }

            // Check specific error codes
            $status = $response->status();
            $body = $response->json();
            
            if ($status === 401) {
                return ['valid' => false, 'reason' => 'Token expired or revoked'];
            }
            
            if ($status === 400) {
                $error = $body['error']['.tag'] ?? 'unknown';
                return ['valid' => false, 'reason' => "Bad request: {$error}"];
            }
            
            return ['valid' => false, 'reason' => "HTTP {$status}"];
            
        } catch (\Exception $e) {
            // Network errors - assume token might still be valid, but we can't verify
            Log::warning('⚠️ No se pudo validar token de Dropbox (error de red)', [
                'error' => $e->getMessage(),
            ]);
            
            // Return valid=true for network errors to avoid unnecessary refreshes
            // The actual operation will fail and trigger a refresh if needed
            return ['valid' => true, 'reason' => 'Network error during validation'];
        }
    }

    /**
     * Execute a Dropbox operation with automatic token refresh on auth failure
     */
    public static function executeWithRetry(callable $operation, int $maxRetries = 2)
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $operation();
                
            } catch (\Exception $e) {
                $lastException = $e;
                $message = strtolower($e->getMessage());
                
                // Check if it's an auth-related error
                $isAuthError = str_contains($message, 'invalid_access_token') ||
                               str_contains($message, 'expired') ||
                               str_contains($message, '401') ||
                               str_contains($message, 'unauthorized') ||
                               str_contains($message, 'invalid_grant');
                
                if ($isAuthError && $attempt < $maxRetries) {
                    Log::warning("🔄 Error de autenticación Dropbox, refrescando token (intento {$attempt}/{$maxRetries})", [
                        'error' => $e->getMessage(),
                    ]);
                    
                    try {
                        self::forceRefreshToken();
                        continue; // Retry with new token
                    } catch (\Exception $refreshError) {
                        Log::error('❌ No se pudo refrescar token después de error de auth', [
                            'original_error' => $e->getMessage(),
                            'refresh_error' => $refreshError->getMessage(),
                        ]);
                        throw $refreshError;
                    }
                }
                
                // Not an auth error or max retries reached
                throw $e;
            }
        }
        
        throw $lastException;
    }
}

/**
 * Custom exception for Dropbox authentication errors
 */
class DropboxAuthException extends \Exception
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

/**
 * Resilient Dropbox adapter that auto-retries on auth failures
 */
class ResilientDropboxAdapter extends FilesystemAdapter
{
    /**
     * Write contents to a file with retry on auth failure
     */
    public function put($path, $contents, $options = [])
    {
        return DropboxService::executeWithRetry(function () use ($path, $contents, $options) {
            return parent::put($path, $contents, $options);
        });
    }

    /**
     * Read a file with retry on auth failure
     */
    public function get($path)
    {
        return DropboxService::executeWithRetry(function () use ($path) {
            return parent::get($path);
        });
    }

    /**
     * Read a file as stream with retry on auth failure
     */
    public function readStream($path)
    {
        return DropboxService::executeWithRetry(function () use ($path) {
            return parent::readStream($path);
        });
    }

    /**
     * Delete a file with retry on auth failure
     */
    public function delete($path)
    {
        return DropboxService::executeWithRetry(function () use ($path) {
            return parent::delete($path);
        });
    }

    /**
     * Check if file exists with retry on auth failure
     */
    public function exists($path)
    {
        return DropboxService::executeWithRetry(function () use ($path) {
            return parent::exists($path);
        });
    }

    /**
     * List directory contents with retry on auth failure
     */
    public function files($directory = null, $recursive = false)
    {
        return DropboxService::executeWithRetry(function () use ($directory, $recursive) {
            return parent::files($directory, $recursive);
        });
    }

    /**
     * Copy a file with retry on auth failure
     */
    public function copy($from, $to)
    {
        return DropboxService::executeWithRetry(function () use ($from, $to) {
            return parent::copy($from, $to);
        });
    }

    /**
     * Move a file with retry on auth failure
     */
    public function move($from, $to)
    {
        return DropboxService::executeWithRetry(function () use ($from, $to) {
            return parent::move($from, $to);
        });
    }
}
