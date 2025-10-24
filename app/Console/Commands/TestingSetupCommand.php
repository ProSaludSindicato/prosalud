<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class TestingSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'testing:setup {--fresh : Recreate the database from scratch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup testing environment with database and migrations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧪 Setting up testing environment...');

        try {
            // Create testing database
            $this->createTestingDatabase();

            // Run migrations
            $this->runMigrations();

            // Run seeders
            $this->runSeeders();

            $this->info('✅ Testing environment setup completed successfully!');
            $this->line('');
            $this->line('📋 Summary:');
            $this->line('   • Database: prosalud-test');
            $this->line('   • Configuration: .env.testing');
            $this->line('   • Migrations: Executed');
            $this->line('   • Seeders: Executed');
            $this->line('');
            $this->line('🚀 To run tests, use:');
            $this->line('   php artisan test --env=testing');

        } catch (\Exception $e) {
            $this->error('❌ Error setting up testing environment: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Create the testing database
     */
    private function createTestingDatabase(): void
    {
        $this->info('📦 Creating testing database...');

        try {
            DB::statement('CREATE DATABASE IF NOT EXISTS `prosalud-test`');
            $this->info('✅ Database "prosalud-test" created successfully');
        } catch (\Exception $e) {
            $this->error('❌ Error creating database: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Run migrations for testing
     * @throws \Exception
     */
    private function runMigrations(): void
    {
        $this->info('🔄 Running migrations...');

        $command = $this->option('fresh') ? 'migrate:fresh' : 'migrate';

        try {
            Artisan::call($command, ['--env' => 'testing']);
            $this->info('✅ Migrations executed successfully');
        } catch (\Exception $e) {
            $this->error('❌ Error running migrations: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Run seeders for testing
     */
    private function runSeeders(): void
    {
        $this->info('🌱 Running seeders...');

        try {
            Artisan::call('db:seed', ['--env' => 'testing']);
            $this->info('✅ Seeders executed successfully');
        } catch (\Exception $e) {
            $this->warn('⚠️  Seeders not executed: ' . $e->getMessage());
        }
    }
}
