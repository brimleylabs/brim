<?php

namespace Brim;

use Brim\Contracts\Embeddable;
use Brim\Contracts\EmbeddingDriver;
use Brim\Contracts\VectorStore;
use Brim\Drivers\EmbeddingManager;
use Brim\Events\BrimBatchCompleted;
use Brim\Events\BrimBatchStarted;
use Brim\Events\BrimEmbeddingCompleted;
use Brim\Events\BrimEmbeddingFailed;
use Brim\Events\BrimEmbeddingStarted;
use Brim\Events\BrimSearchCompleted;
use Brim\Events\BrimSearchStarted;
use Brim\Exceptions\BrimException;
use Brim\Jobs\GenerateEmbedding;
use Brim\Stores\VectorStoreManager;
use Brim\Support\TextChunker;
use Brim\Telemetry\TelemetryCollector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Brim
{
    protected EmbeddingManager $embeddings;
    protected VectorStoreManager $stores;
    protected TextChunker $chunker;
    protected ?TelemetryCollector $telemetry = null;

    public function __construct(
        EmbeddingManager $embeddings,
        VectorStoreManager $stores,
        ?TextChunker $chunker = null,
        ?TelemetryCollector $telemetry = null
    ) {
        $this->embeddings = $embeddings;
        $this->stores = $stores;
        $this->chunker = $chunker ?? new TextChunker();
        $this->telemetry = $telemetry;
    }

    /**
     * Generate and store embeddings for a model.
     *
     * @param Model&Embeddable $model
     * @return void
     * @throws BrimException
     */
    public function generateFor(Model $model): void
    {
        $this->validateEmbeddable($model);

        $text = $model->toEmbeddableText();
        $namespace = $model->getEmbeddingNamespace();

        $driver = $this->driver();
        $driverName = config('brim.embeddings.driver', 'ollama');
        $modelName = $driver->modelName();

        $startTime = microtime(true);

        // Dispatch started event
        $startedEvent = new BrimEmbeddingStarted($model, $driverName, strlen($text));
        event($startedEvent);
        $this->telemetry?->recordEmbeddingStarted($startedEvent);

        try {
            // Chunk text if needed
            $chunks = $this->chunker->chunk($text, $modelName);

            // Generate embeddings for all chunks
            $vectors = $driver->embedBatch($chunks);

            $embeddingEndTime = microtime(true);

            // Store all vectors
            $this->store()->store($model, $vectors, $modelName, $namespace);

            // Dispatch completed event
            $completedEvent = new BrimEmbeddingCompleted(
                $model,
                $driverName,
                count($vectors),
                $driver->dimensions(),
                $startTime,
                $embeddingEndTime
            );
            event($completedEvent);
            $this->telemetry?->recordEmbeddingCompleted($completedEvent);
        } catch (\Throwable $e) {
            // Dispatch failed event
            $failedEvent = new BrimEmbeddingFailed($model, $driverName, $e, 0, $startTime);
            event($failedEvent);
            $this->telemetry?->recordEmbeddingFailed($failedEvent);

            throw $e;
        }
    }

    /**
     * Queue embedding generation for a model.
     *
     * @param Model&Embeddable $model
     * @return void
     */
    public function queueFor(Model $model): void
    {
        $this->validateEmbeddable($model);

        GenerateEmbedding::dispatch($model);
    }

    /**
     * Generate or queue embedding based on config.
     *
     * @param Model&Embeddable $model
     * @return void
     */
    public function generateOrQueueFor(Model $model): void
    {
        if (config('brim.queue', false)) {
            $this->queueFor($model);
        } else {
            $this->generateFor($model);
        }
    }

    /**
     * Delete embeddings for a model.
     *
     * @param Model $model
     * @return void
     */
    public function deleteFor(Model $model): void
    {
        $this->store()->delete($model);
    }

    /**
     * Search for similar models.
     *
     * @param string $modelClass
     * @param string $query
     * @param int|null $limit
     * @param float|null $minSimilarity
     * @param string|null $namespace
     * @return Collection
     */
    public function search(
        string $modelClass,
        string $query,
        ?int $limit = null,
        ?float $minSimilarity = null,
        ?string $namespace = null
    ): Collection {
        $limit = $limit ?? config('brim.search.limit', 10);
        $minSimilarity = $minSimilarity ?? config('brim.search.min_similarity', 0.0);

        $startTime = microtime(true);

        // Dispatch started event
        $startedEvent = new BrimSearchStarted($modelClass, $query, $limit, $minSimilarity, $namespace);
        event($startedEvent);
        $this->telemetry?->recordSearchStarted($startedEvent);

        // Generate embedding for the search query
        $queryVector = $this->driver()->embed($query);

        $embeddingEndTime = microtime(true);

        // Search the vector store
        $results = $this->store()->search(
            $modelClass,
            $queryVector,
            $limit,
            $minSimilarity,
            $namespace
        );

        $searchEndTime = microtime(true);

        // Load the actual models
        $modelIds = $results->pluck('model_id')->toArray();
        $similarities = $results->pluck('similarity', 'model_id')->toArray();

        if (empty($modelIds)) {
            $models = collect();
        } else {
            $models = (new $modelClass)
                ->whereIn((new $modelClass)->getKeyName(), $modelIds)
                ->get()
                ->each(function ($model) use ($similarities) {
                    $model->similarity_score = $similarities[$model->getKey()] ?? 0;
                })
                ->sortByDesc('similarity_score')
                ->values();
        }

        // Dispatch completed event
        $completedEvent = new BrimSearchCompleted(
            $modelClass,
            $query,
            $models,
            $startTime,
            $embeddingEndTime,
            $searchEndTime
        );
        event($completedEvent);
        $this->telemetry?->recordSearchCompleted($completedEvent);

        // Add debug timings if enabled
        if (config('brim.telemetry.debug', false)) {
            $models->transform(function ($model) use ($completedEvent) {
                $model->_brim_debug = [
                    'total_ms' => $completedEvent->totalDuration,
                    'embedding_ms' => $completedEvent->embeddingTime,
                    'search_ms' => $completedEvent->searchTime,
                    'hydration_ms' => $completedEvent->hydrationTime,
                ];
                return $model;
            });
        }

        return $models;
    }

    /**
     * Find models similar to a given model.
     *
     * @param Model $model
     * @param int $limit
     * @return Collection
     */
    public function findSimilar(Model $model, int $limit = 5): Collection
    {
        return $this->store()->findSimilar($model, $limit);
    }

    /**
     * Check if embeddings exist for a model.
     *
     * @param Model $model
     * @return bool
     */
    public function existsFor(Model $model): bool
    {
        return $this->store()->exists($model);
    }

    /**
     * Get the chunk count for a model.
     *
     * @param Model $model
     * @return int
     */
    public function chunkCountFor(Model $model): int
    {
        return $this->store()->chunkCount($model);
    }

    /**
     * Batch generate embeddings for multiple models.
     *
     * @param string $modelClass
     * @param Collection|array $models
     * @return array{processed: int, failed: int, errors: array}
     */
    public function batchGenerate(string $modelClass, $models): array
    {
        $models = $models instanceof Collection ? $models : collect($models);
        $startTime = microtime(true);

        // Dispatch batch started event
        $batchStartedEvent = new BrimBatchStarted($modelClass, $models->count(), 'embed');
        event($batchStartedEvent);
        $this->telemetry?->recordBatchStarted($batchStartedEvent);

        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($models as $model) {
            try {
                $this->generateFor($model);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'model_id' => $model->getKey(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Dispatch batch completed event
        $batchCompletedEvent = new BrimBatchCompleted(
            $modelClass,
            $processed,
            $failed,
            'embed',
            $startTime
        );
        event($batchCompletedEvent);
        $this->telemetry?->recordBatchCompleted($batchCompletedEvent);

        return [
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
            'duration_ms' => $batchCompletedEvent->duration,
            'throughput' => $batchCompletedEvent->throughput,
        ];
    }

    /**
     * Get embedding driver.
     *
     * @param string|null $name
     * @return EmbeddingDriver
     */
    public function driver(?string $name = null): EmbeddingDriver
    {
        return $this->embeddings->driver($name);
    }

    /**
     * Get vector store.
     *
     * @param string|null $name
     * @return VectorStore
     */
    public function store(?string $name = null): VectorStore
    {
        return $this->stores->driver($name);
    }

    /**
     * Get embedding statistics.
     *
     * @return array
     */
    public function stats(): array
    {
        return $this->store()->stats();
    }

    /**
     * Check driver health.
     *
     * @param string|null $driver
     * @return array
     */
    public function healthCheck(?string $driver = null): array
    {
        return $this->driver($driver)->healthCheck();
    }

    /**
     * Get the telemetry collector.
     *
     * @return TelemetryCollector|null
     */
    public function telemetry(): ?TelemetryCollector
    {
        return $this->telemetry;
    }

    /**
     * Get telemetry statistics.
     *
     * @param string $period
     * @return array
     */
    public function telemetryStats(string $period = '24h'): array
    {
        return $this->telemetry?->getStats($period) ?? [];
    }

    /**
     * Validate that a model implements Embeddable.
     *
     * @param Model $model
     * @return void
     * @throws BrimException
     */
    protected function validateEmbeddable(Model $model): void
    {
        if (!$model instanceof Embeddable) {
            throw BrimException::modelNotEmbeddable(get_class($model));
        }
    }

    /**
     * Get the embedding manager.
     *
     * @return EmbeddingManager
     */
    public function getEmbeddingManager(): EmbeddingManager
    {
        return $this->embeddings;
    }

    /**
     * Get the vector store manager.
     *
     * @return VectorStoreManager
     */
    public function getVectorStoreManager(): VectorStoreManager
    {
        return $this->stores;
    }

    /**
     * Get the text chunker.
     *
     * @return TextChunker
     */
    public function getChunker(): TextChunker
    {
        return $this->chunker;
    }
}
