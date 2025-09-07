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
        return new class($config) extends \Illuminate\Filesystem\FilesystemAdapter {
            public function __construct($config)
            {
                $accessToken = \App\Services\DropboxService::getAccessToken();

                $client = new \Spatie\Dropbox\Client($accessToken);
                $adapter = new \Spatie\FlysystemDropbox\DropboxAdapter($client, $config['root'] ?? '');
                $filesystem = new \League\Flysystem\Filesystem($adapter);

                parent::__construct($filesystem, $adapter, $config);
            }
        };
      });

      Storage::disk('dropbox');
    }
}
