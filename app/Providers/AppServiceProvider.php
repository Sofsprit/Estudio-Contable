<?php

namespace App\Providers;

use App\Services\DropboxService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Spatie\Dropbox\Client;
use Spatie\FlysystemDropbox\DropboxAdapter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
      Storage::extend('dropbox', function ($app, $config) {
        $accessToken = DropboxService::getAccessToken();

        $client = new \Spatie\Dropbox\Client($accessToken);
        $adapter = new \Spatie\FlysystemDropbox\DropboxAdapter($client, $config['root'] ?? '');

        $filesystem = new \League\Flysystem\Filesystem($adapter);
        return new \Illuminate\Filesystem\FilesystemAdapter($filesystem, $adapter, $config);
      });

      Storage::disk('dropbox');
    }
}
