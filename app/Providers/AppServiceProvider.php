<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\WebDAV\WebDAVAdapter;
use Sabre\DAV\Client as WebDavClient;
use App\Services\{NextcloudPhotoRepository, ImageProbe};

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the Flysystem operator for the Nextcloud disk when requested
        $this->app->when([NextcloudPhotoRepository::class, ImageProbe::class])
            ->needs(FilesystemOperator::class)
            ->give(function ($app) {
                $config = $app['config']['filesystems.disks.nextcloud'] ?? [];
                $client = new WebDavClient([
                    'baseUri'  => $config['baseUri'] ?? '',
                    'userName' => $config['userName'] ?? '',
                    'password' => $config['password'] ?? '',
                ]);
                $adapter = new WebDAVAdapter($client, '/');
                return new Flysystem($adapter);
            });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register a custom WebDAV driver for Nextcloud
        Storage::extend('webdav', function ($app, array $config) {
            $client = new WebDavClient([
                'baseUri'  => $config['baseUri'] ?? '',
                'userName' => $config['userName'] ?? '',
                'password' => $config['password'] ?? '',
            ]);

            // Base URI already points to the user root, no extra prefix
            $adapter = new WebDAVAdapter($client, '');
            $filesystem = new Flysystem($adapter);

            // Return Laravel adapter wrapping Flysystem operator
            return new \Illuminate\Filesystem\FilesystemAdapter($filesystem, $adapter, $config);
        });
    }
}
