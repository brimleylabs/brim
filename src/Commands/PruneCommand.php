<?php

namespace Brim\Commands;

use Brim\Stores\PgVectorStore;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brim:prune
                            {--dry-run : Only show what would be deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove orphaned embeddings where the model no longer exists';

    /**
     * Execute the console command.
     *
     * @param PgVectorStore $store
     * @return int
     */
    public function handle(PgVectorStore $store): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Searching for orphaned embeddings...');
        $this->newLine();

        // Find orphaned embeddings
        $orphaned = $store->findOrphaned();
        $count = $orphaned->count();

        if ($count === 0) {
            $this->info('No orphaned embeddings found.');
            return Command::SUCCESS;
        }

        // Group by model type for display
        $grouped = $orphaned->groupBy('model_type');

        $this->table(
            ['Model Type', 'Orphaned Count'],
            $grouped->map(fn($items, $type) => [$type, $items->count()])->values()->toArray()
        );

        $this->newLine();
        $this->info("Found {$count} orphaned embedding(s) to prune.");

        if ($dryRun) {
            $this->warn('Dry run mode - no embeddings were deleted.');
            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to delete these orphaned embeddings?', true)) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        // Delete orphaned embeddings
        $this->info('Deleting orphaned embeddings...');

        $deleted = $store->deleteOrphaned();

        $this->newLine();
        $this->info("Successfully deleted {$deleted} orphaned embedding(s).");

        return Command::SUCCESS;
    }
}
