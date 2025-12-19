<?php

namespace App\Console\Commands;

use App\Models\ChatbotConversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};

class ClearChatbotConversationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chatbot:clear 
                            {--force : Skip confirmation prompt}
                            {--backup : Create backup before clearing}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all ChatbotConversation records from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧹 Chatbot Conversations Cleanup Command');
        $this->line('==========================================');

        // Get current count
        $count = ChatbotConversation::count();

        if ($count === 0) {
            $this->info('✅ Chatbot conversations table is already empty. Nothing to clear.');

            return 0;
        }

        // Show current statistics
        $this->showStatistics($count);

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No data will be deleted');
            $this->info('The following records would be deleted:');
            $this->showDetails();

            return 0;
        }

        // Backup option
        if ($this->option('backup')) {
            $this->createBackup($count);
        }

        // Confirmation prompt (unless --force is used)
        if (!$this->option('force')) {
            $this->warn('⚠️  WARNING: This will permanently delete ALL chatbot conversation records.');
            $this->line('');
            $this->warn("⚠️  Total records to delete: {$count}");
            $this->warn('⚠️  This action cannot be undone!');

            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }

            // Double confirmation for safety
            $this->warn("This will delete {$count} chatbot conversation records.");
            if (!$this->confirm('Are you absolutely sure? This action cannot be undone!')) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }
        }

        // Perform the deletion
        $this->info('🗑️  Clearing chatbot conversations...');

        try {
            // Log the operation
            Log::warning('Chatbot conversations cleared via artisan command', [
                'command' => 'chatbot:clear',
                'count' => $count,
                'user' => 'artisan_command',
                'timestamp' => now()->toISOString(),
                'options' => [
                    'force' => $this->option('force'),
                    'backup' => $this->option('backup'),
                    'dry_run' => $this->option('dry-run'),
                ],
            ]);

            // Delete all chatbot conversations
            $this->info('  → Deleting Chatbot Conversations...');
            ChatbotConversation::truncate();
            $this->info('    ✓ Chatbot Conversations deleted');

            $this->info('');
            $this->info('✅ Successfully cleared all chatbot conversations.');
            $this->info("🗑️  Deleted {$count} records total.");

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Error occurred while clearing chatbot conversations:');
            $this->error($e->getMessage());

            Log::error('Error clearing chatbot conversations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString(),
            ]);

            return 1;
        }
    }

    /**
     * Show statistics for chatbot conversations.
     */
    private function showStatistics(int $count): void
    {
        $this->line('');
        $this->info('📊 Current Records in Database:');
        $this->line('');
        $this->line("💬 Chatbot Conversations: {$count}");
        $this->line('');
    }

    /**
     * Show detailed information about records.
     */
    private function showDetails(): void
    {
        $this->line('');

        if (ChatbotConversation::count() > 0) {
            $this->info('Recent Chatbot Conversations (last 10):');
            $recent = ChatbotConversation::orderBy('created_at', 'desc')->limit(10)->get();
            foreach ($recent as $conv) {
                $question = substr($conv->user_question, 0, 60);
                $date = $conv->created_at ? $conv->created_at->format('Y-m-d H:i:s') : 'N/A';
                $this->line("  • ID: {$conv->id} - Q: {$question}... - Created: {$date}");
            }
            $this->line('');
        }
    }

    /**
     * Create backup of data before clearing.
     */
    private function createBackup(int $count): void
    {
        $this->info('💾 Creating backup...');

        try {
            $backupData = [
                'timestamp' => now()->toISOString(),
                'chatbot_conversations' => ChatbotConversation::all()->toArray(),
            ];

            $backupFileName = 'chatbot_conversations_backup_' . now()->format('Y_m_d_H_i_s') . '.json';
            $backupPath = storage_path('app/backups/' . $backupFileName);

            // Create backup directory if it doesn't exist
            if (!file_exists(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }

            // Save backup
            file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));

            $this->info("✅ Backup created: {$backupPath}");
            $this->info("📁 Backup contains {$count} records total");

            Log::info('Chatbot conversations backup created', [
                'backup_file' => $backupFileName,
                'count' => $count,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            $this->error('❌ Error creating backup: ' . $e->getMessage());
            Log::error('Error creating chatbot conversations backup', [
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ]);
        }
    }
}


