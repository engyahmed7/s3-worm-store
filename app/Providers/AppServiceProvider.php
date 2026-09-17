<?php

namespace App\Providers;

use App\Services\WormArchive;
use Aws\S3\S3Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WormArchive::class, function ($app): WormArchive {
            $disk = $app['config']->get('filesystems.disks.minio');

            return new WormArchive(
                client: new S3Client([
                    'version' => 'latest',
                    'region' => $disk['region'] ?? 'us-east-1',
                    'endpoint' => $disk['endpoint'],
                    'use_path_style_endpoint' => (bool) $disk['use_path_style_endpoint'],
                    'credentials' => [
                        'key' => $disk['key'],
                        'secret' => $disk['secret'],
                    ],
                    'http' => [
                        'connect_timeout' => 2,
                        'timeout' => 10,
                    ],
                ]),
                bucket: $disk['bucket'],
                lockMode: $app['config']->get('worm.lock_mode'),
                retentionDays: (int) $app['config']->get('worm.retention_days'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
