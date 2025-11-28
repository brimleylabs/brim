<?php

namespace Brim;

use Brim\Commands\EmbedCommand;
use Brim\Commands\InstallCommand;
use Brim\Commands\PruneCommand;
use Brim\Commands\StatusCommand;
use Brim\Commands\TelemetryCommand;
use Brim\Contracts\EmbeddingDriver;
use Brim\Contracts\VectorStore;
use Brim\Drivers\EmbeddingManager;
use Brim\Stores\PgVectorStore;
use Brim\Stores\VectorStoreManager;
use Brim\Support\TextChunker;
use Brim\Telemetry\TelemetryCollector;
use Illuminate\Support\ServiceProvider;

class BrimServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/brim.php',
            'brim'
        );

        // Register Embedding Manager
        $this->app->singleton(EmbeddingManager::class, function ($app) {
            return new EmbeddingManager($app);
        });

        // Register Vector Store Manager
        $this->app->singleton(VectorStoreManager::class, function ($app) {
            return new VectorStoreManager($app);
        });

        // Register PgVectorStore
        $this->app->singleton(PgVectorStore::class, function ($app) {
            return new PgVectorStore(
                $app['config']->get('brim.vector_store', [])
            );
        });

        // Register Text Chunker
        $this->app->singleton(TextChunker::class, function ($app) {
            return new TextChunker(
                $app['config']->get('brim.chunking', [])
            );
        });

        // Register Telemetry Collector
        $this->app->singleton(TelemetryCollector::class, function ($app) {
            return new TelemetryCollector(
                $app['config']->get('brim.telemetry', [])
            );
        });

        // Register main Brim service
        $this->app->singleton(Brim::class, function ($app) {
            $telemetry = null;

            // Only inject telemetry if enabled
            if ($app['config']->get('brim.telemetry.enabled', true)) {
                $telemetry = $app->make(TelemetryCollector::class);
            }

            return new Brim(
                $app->make(EmbeddingManager::class),
                $app->make(VectorStoreManager::class),
                $app->make(TextChunker::class),
                $telemetry
            );
        });

        // Bind contracts to implementations
        $this->app->bind(EmbeddingDriver::class, function ($app) {
            return $app->make(EmbeddingManager::class)->driver();
        });

        $this->app->bind(VectorStore::class, function ($app) {
            return $app->make(VectorStoreManager::class)->driver();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/brim.php' => config_path('brim.php'),
        ], 'brim-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'brim-migrations');

        // Load migrations from package
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                EmbedCommand::class,
                PruneCommand::class,
                StatusCommand::class,
                TelemetryCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            Brim::class,
            EmbeddingManager::class,
            VectorStoreManager::class,
            PgVectorStore::class,
            TextChunker::class,
            TelemetryCollector::class,
            EmbeddingDriver::class,
            VectorStore::class,
        ];
    }
}
