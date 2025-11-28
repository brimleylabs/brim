<?php

namespace Brim\Commands;

use Brim\Brim;
use Brim\Contracts\Embeddable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class EmbedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brim:embed
                            {model : The model class to generate embeddings for}
                            {--batch=50 : Number of models to process at a time}
                            {--force : Regenerate embeddings even if they exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate embeddings for all instances of a model';

    /**
     * Execute the console command.
     *
     * @param Brim $brim
     * @return int
     */
    public function handle(Brim $brim): int
    {
        $modelClass = $this->argument('model');
        $batchSize = (int) $this->option('batch');
        $force = $this->option('force');

        // Validate model class
        if (!class_exists($modelClass)) {
            $this->error("Model class [{$modelClass}] does not exist.");
            return Command::FAILURE;
        }

        $model = new $modelClass;

        if (!$model instanceof Model) {
            $this->error("Class [{$modelClass}] is not an Eloquent model.");
            return Command::FAILURE;
        }

        if (!$model instanceof Embeddable) {
            $this->error("Model [{$modelClass}] does not implement the Embeddable interface.");
            $this->line('Add the HasEmbeddings trait to your model to make it embeddable.');
            return Command::FAILURE;
        }

        // Get total count
        $total = $modelClass::count();

        if ($total === 0) {
            $this->info("No {$modelClass} records found.");
            return Command::SUCCESS;
        }

        $this->info("Generating embeddings for {$total} {$modelClass} records...");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        // Process in batches
        $modelClass::query()
            ->chunk($batchSize, function ($models) use ($brim, $force, $bar, &$processed, &$skipped, &$errors) {
                foreach ($models as $model) {
                    try {
                        // Skip if embedding exists and not forcing
                        if (!$force && $brim->existsFor($model)) {
                            $skipped++;
                            $bar->advance();
                            continue;
                        }

                        $brim->generateFor($model);
                        $processed++;
                    } catch (\Throwable $e) {
                        $errors++;
                        // Log error but continue processing
                        logger()->error('Brim embedding generation failed', [
                            'model' => get_class($model),
                            'id' => $model->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Embedding generation complete!');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total', $total],
                ['Processed', $processed],
                ['Skipped (existing)', $skipped],
                ['Errors', $errors],
            ]
        );

        if ($errors > 0) {
            $this->warn("Check the logs for error details.");
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
