<?php

namespace Brim\Commands;

use Brim\Brim;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brim:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show Brim status and statistics';

    /**
     * Execute the console command.
     *
     * @param Brim $brim
     * @return int
     */
    public function handle(Brim $brim): int
    {
        $this->newLine();
        $this->components->info('Brim Status');
        $this->newLine();

        // Driver health check
        $this->displayDriverStatus($brim);

        // Embedding statistics
        $this->displayEmbeddingStats($brim);

        // Configuration
        $this->displayConfiguration();

        // Queue status
        $this->displayQueueStatus();

        return Command::SUCCESS;
    }

    /**
     * Display driver health status.
     *
     * @param Brim $brim
     * @return void
     */
    protected function displayDriverStatus(Brim $brim): void
    {
        $this->components->twoColumnDetail('Driver', config('brim.embeddings.driver', 'ollama'));

        $health = $brim->healthCheck();

        $status = $health['healthy']
            ? '<fg=green>Healthy</>'
            : '<fg=red>Unhealthy</>';

        $this->components->twoColumnDetail('Status', $status);
        $this->components->twoColumnDetail('Message', $health['message']);

        if (isset($health['details']['model'])) {
            $this->components->twoColumnDetail('Model', $health['details']['model']);
        }

        if (isset($health['details']['model_installed'])) {
            $installed = $health['details']['model_installed']
                ? '<fg=green>Yes</>'
                : '<fg=yellow>No</>';
            $this->components->twoColumnDetail('Model Installed', $installed);
        }

        $this->newLine();
    }

    /**
     * Display embedding statistics.
     *
     * @param Brim $brim
     * @return void
     */
    protected function displayEmbeddingStats(Brim $brim): void
    {
        $this->components->info('Embedding Statistics');
        $this->newLine();

        try {
            $stats = $brim->stats();

            $this->components->twoColumnDetail('Total Embeddings', number_format($stats['total']));
            $this->components->twoColumnDetail('Unique Models', number_format($stats['models']));

            if (!empty($stats['by_type'])) {
                $this->newLine();
                $this->line('  <fg=gray>By Model Type:</>');

                foreach ($stats['by_type'] as $type => $data) {
                    $shortType = class_basename($type);
                    $this->components->twoColumnDetail(
                        "  {$shortType}",
                        "{$data['models']} models, {$data['embeddings']} chunks"
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->components->warn('Could not fetch embedding statistics: ' . $e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Display configuration.
     *
     * @return void
     */
    protected function displayConfiguration(): void
    {
        $this->components->info('Configuration');
        $this->newLine();

        $autoSync = config('brim.auto_sync', true) ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>';
        $queue = config('brim.queue', false) ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>';
        $chunking = config('brim.chunking.enabled', true) ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>';

        $this->components->twoColumnDetail('Auto Sync', $autoSync);
        $this->components->twoColumnDetail('Queue Mode', $queue);
        $this->components->twoColumnDetail('Chunking', $chunking);
        $this->components->twoColumnDetail('Batch Size', config('brim.batch_size', 50));
        $this->components->twoColumnDetail('Search Limit', config('brim.search.limit', 10));
        $this->components->twoColumnDetail('Min Similarity', config('brim.search.min_similarity', 0.0));

        $this->newLine();
    }

    /**
     * Display queue status.
     *
     * @return void
     */
    protected function displayQueueStatus(): void
    {
        if (!config('brim.queue', false)) {
            return;
        }

        $this->components->info('Queue Status');
        $this->newLine();

        try {
            // Check for pending jobs (this is driver-specific)
            $connection = config('queue.default');

            if ($connection === 'database') {
                $pending = DB::table('jobs')
                    ->where('payload', 'like', '%GenerateEmbedding%')
                    ->count();

                $failed = DB::table('failed_jobs')
                    ->where('payload', 'like', '%GenerateEmbedding%')
                    ->count();

                $this->components->twoColumnDetail('Pending Jobs', $pending);
                $this->components->twoColumnDetail('Failed Jobs', $failed > 0 ? "<fg=red>{$failed}</>" : '0');
            } else {
                $this->components->twoColumnDetail('Queue Connection', $connection);
                $this->line('  <fg=gray>Job counts not available for this queue driver.</>');
            }
        } catch (\Throwable $e) {
            $this->components->warn('Could not fetch queue status: ' . $e->getMessage());
        }

        $this->newLine();
    }
}
