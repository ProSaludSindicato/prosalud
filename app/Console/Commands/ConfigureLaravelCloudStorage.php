<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ConfigureLaravelCloudStorage extends Command
{
    protected $signature = 'laravel-cloud:configure-storage';
    protected $description = 'Configure storage disks based on Laravel Cloud configuration';

    public function handle()
    {
        $config = env('LARAVEL_CLOUD_DISK_CONFIG');
        
        if (!$config) {
            $this->error('LARAVEL_CLOUD_DISK_CONFIG environment variable not found');
            return 1;
        }

        try {
            $disks = json_decode($config, true);
            
            if (!$disks) {
                $this->error('Invalid JSON in LARAVEL_CLOUD_DISK_CONFIG');
                return 1;
            }

            $this->info('Found ' . count($disks) . ' disk configurations');

            foreach ($disks as $disk) {
                $this->configureDisk($disk);
            }

            $this->info('Storage configuration completed successfully!');
            return 0;

        } catch (\Exception $e) {
            $this->error('Error parsing configuration: ' . $e->getMessage());
            return 1;
        }
    }

    private function configureDisk($disk)
    {
        $diskName = $disk['disk'];
        $this->info("Configuring disk: {$diskName}");

        // Set environment variables for this disk
        $envVars = [
            "AWS_{$diskName}_ACCESS_KEY_ID" => $disk['access_key_id'],
            "AWS_{$diskName}_SECRET_ACCESS_KEY" => $disk['access_key_secret'],
            "AWS_{$diskName}_DEFAULT_REGION" => $disk['default_region'],
            "AWS_{$diskName}_BUCKET" => $disk['bucket'],
            "AWS_{$diskName}_URL" => $disk['url'],
            "AWS_{$diskName}_ENDPOINT" => $disk['endpoint'],
            "AWS_{$diskName}_USE_PATH_STYLE_ENDPOINT" => $disk['use_path_style_endpoint'] ? 'true' : 'false',
        ];

        // Update .env file
        $this->updateEnvFile($envVars);

        // Test the disk configuration
        $this->testDisk($diskName);
    }

    private function updateEnvFile($envVars)
    {
        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            $this->warn('.env file not found, skipping environment variable updates');
            return;
        }

        $envContent = File::get($envPath);
        
        foreach ($envVars as $key => $value) {
            $pattern = "/^{$key}=.*$/m";
            $replacement = "{$key}={$value}";
            
            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                $envContent .= "\n{$replacement}";
            }
        }

        File::put($envPath, $envContent);
        $this->info('Updated .env file with new variables');
    }

    private function testDisk($diskName)
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk($diskName);
            $files = $disk->files();
            $this->info("✓ Disk '{$diskName}' is accessible (found " . count($files) . " files)");
        } catch (\Exception $e) {
            $this->warn("⚠ Disk '{$diskName}' test failed: " . $e->getMessage());
        }
    }
}
