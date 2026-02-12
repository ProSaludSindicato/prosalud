<?php

namespace App\Console\Commands;

use App\Services\SstDotacionService;
use Illuminate\Console\Command;

class ClearDotacionCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dotacion:clear-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the static inventory cache for dotación/EPP';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Clearing dotación/EPP inventory cache...');
        
        SstDotacionService::clearInventoryCache();
        
        $this->info('✓ Dotación/EPP inventory cache cleared successfully!');
        
        return Command::SUCCESS;
    }
}
