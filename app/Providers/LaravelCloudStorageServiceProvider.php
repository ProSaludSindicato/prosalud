<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Log;

class LaravelCloudStorageServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->configureLaravelCloudDisks();
    }

    /**
     * Configure Laravel Cloud storage disks dynamically
     */
    private function configureLaravelCloudDisks(): void
    {
        $config = env('LARAVEL_CLOUD_DISK_CONFIG');

        if (!$config) {
            return;
        }

        try {
            $disks = json_decode($config, true);

            if (!$disks) {
                return;
            }

            foreach ($disks as $diskConfig) {
                $this->configureDisk($diskConfig);
            }

        } catch (\Exception $e) {
            // Silently fail if configuration is invalid
            Log::warning('Invalid LARAVEL_CLOUD_DISK_CONFIG: ' . $e->getMessage());
        }
    }

    /**
     * Configure a specific disk
     */
    private function configureDisk(array $diskConfig): void
    {
        $diskName = $diskConfig['disk'];

        // Configure prosalud-public disk using the public disk configuration
        if ($diskName === 'public') {
            Config::set("filesystems.disks.prosalud-public", [
                'driver' => 's3',
                'key' => $diskConfig['access_key_id'],
                'secret' => $diskConfig['access_key_secret'],
                'region' => $diskConfig['default_region'],
                'bucket' => $diskConfig['bucket'],
                'url' => $diskConfig['url'],
                'endpoint' => $diskConfig['endpoint'],
                'use_path_style_endpoint' => $diskConfig['use_path_style_endpoint'],
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ]);
        }
    }
}
