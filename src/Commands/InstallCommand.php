<?php

namespace Brim\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brim:install
                            {--force : Overwrite existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Brim package resources';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Installing Brim...');
        $this->newLine();

        // Publish config
        $this->info('Publishing configuration...');
        $this->call('vendor:publish', [
            '--tag' => 'brim-config',
            '--force' => $this->option('force'),
        ]);

        // Publish migrations
        $this->info('Publishing migrations...');
        $this->call('vendor:publish', [
            '--tag' => 'brim-migrations',
            '--force' => $this->option('force'),
        ]);

        // Run migrations
        if ($this->confirm('Would you like to run the migrations now?', true)) {
            $this->info('Running migrations...');
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('Brim installed successfully!');
        $this->newLine();

        // Next steps
        $this->components->info('Next Steps:');
        $this->newLine();

        $this->line('  1. Install Ollama (if not already installed):');
        $this->line('     <comment>curl -fsSL https://ollama.com/install.sh | sh</comment>');
        $this->newLine();

        $this->line('  2. Pull the default embedding model:');
        $this->line('     <comment>ollama pull nomic-embed-text</comment>');
        $this->newLine();

        $this->line('  3. Add the HasEmbeddings trait to your models:');
        $this->line('     <comment>use Brim\Traits\HasEmbeddings;</comment>');
        $this->line('     <comment>class Article extends Model { use HasEmbeddings; }</comment>');
        $this->newLine();

        $this->line('  4. Define embeddable fields in your model:');
        $this->line('     <comment>protected array $embeddable = [\'title\', \'body\'];</comment>');
        $this->newLine();

        $this->line('  5. Generate embeddings for existing models:');
        $this->line('     <comment>php artisan brim:embed "App\Models\Article"</comment>');
        $this->newLine();

        $this->line('  6. Check status:');
        $this->line('     <comment>php artisan brim:status</comment>');
        $this->newLine();

        return Command::SUCCESS;
    }
}
