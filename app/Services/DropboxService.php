<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DropboxService
{
    public static function getAccessToken(): string
    {
      $token = Cache::get('dropbox_access_token');

      if ($token && self::isTokenValid($token)) {
        return $token;
      }

      return self::refreshToken();
    }

    protected static function refreshToken(): string
    {
      $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => env('DROPBOX_REFRESH_TOKEN'),
        'client_id' => env('DROPBOX_CLIENT_ID'),
        'client_secret' => env('DROPBOX_CLIENT_SECRET'),
      ]);

      if ($response->failed()) {
        throw new \Exception('Failed to refresh Dropbox access token');
      }

      $newToken = $response->json('access_token');

      Cache::put('dropbox_access_token', $newToken, now()->addSeconds($response->json('expires_in', 14400)));

      return $newToken;
    }

    protected static function isTokenValid(string $token): bool
    {
      $check = Http::withToken($token)
        ->post('https://api.dropboxapi.com/2/users/get_current_account');

      return $check->ok();
    }
}
