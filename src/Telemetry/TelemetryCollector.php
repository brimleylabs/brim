<?php

namespace Brim\Telemetry;

use Brim\Events\BrimBatchCompleted;
use Brim\Events\BrimBatchStarted;
use Brim\Events\BrimEmbeddingCompleted;
use Brim\Events\BrimEmbeddingFailed;
use Brim\Events\BrimEmbeddingStarted;
use Brim\Events\BrimSearchCompleted;
use Brim\Events\BrimSearchStarted;
use Brim\Models\TelemetryEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelemetryCollector
{
    protected array $config;
    protected bool $enabled;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('brim.telemetry', []);
        $this->enabled = $this->config['enabled'] ?? true;
    }

    /**
     * Check if telemetry is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if a specific tracking category is enabled.
     */
    public function shouldTrack(string $category): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Apply sampling rate
        $sampleRate = $this->config['sample_rate'] ?? 1.0;
        if ($sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate) {
            return false;
        }

        return $this->config['track'][$category] ?? true;
    }

    /**
     * Record an embedding started event.
     */
    public function recordEmbeddingStarted(BrimEmbeddingStarted $event): void
    {
        if (!$this->shouldTrack('embeddings')) {
            return;
        }

        $this->store('embedding.started', [
            'model_type' => $event->metadata['model_type'],
            'model_id' => $event->metadata['model_id'],
            'driver' => $event->driver,
            'text_length' => $event->textLength,
            'queued' => $event->queued,
            'queue_wait_time' => $event->queueWaitTime,
        ]);

        $this->log('Embedding started', [
            'model' => $event->metadata['model_type'] . ':' . $event->metadata['model_id'],
            'driver' => $event->driver,
            'text_length' => $event->textLength,
        ]);
    }

    /**
     * Record an embedding completed event.
     */
    public function recordEmbeddingCompleted(BrimEmbeddingCompleted $event): void
    {
        if (!$this->shouldTrack('embeddings')) {
            return;
        }

        $this->store('embedding.completed', [
            'model_type' => $event->metadata['model_type'],
            'model_id' => $event->metadata['model_id'],
            'driver' => $event->driver,
            'chunk_count' => $event->chunkCount,
            'dimensions' => $event->dimensions,
            'duration_ms' => $event->duration,
            'embedding_time_ms' => $event->embeddingTime,
            'storage_time_ms' => $event->storageTime,
        ]);

        $this->log('Embedding completed', [
            'model' => $event->metadata['model_type'] . ':' . $event->metadata['model_id'],
            'duration_ms' => $event->duration,
            'chunks' => $event->chunkCount,
        ]);
    }

    /**
     * Record an embedding failed event.
     */
    public function recordEmbeddingFailed(BrimEmbeddingFailed $event): void
    {
        if (!$this->shouldTrack('failures')) {
            return;
        }

        $this->store('embedding.failed', [
            'model_type' => $event->metadata['model_type'],
            'model_id' => $event->metadata['model_id'],
            'driver' => $event->driver,
            'error_class' => $event->metadata['error_class'],
            'error_message' => $event->metadata['error_message'],
            'retry_attempt' => $event->retryAttempt,
            'duration_ms' => $event->duration,
        ]);

        $this->log('Embedding failed', [
            'model' => $event->metadata['model_type'] . ':' . $event->metadata['model_id'],
            'error' => $event->metadata['error_message'],
            'retry' => $event->retryAttempt,
        ], 'warning');
    }

    /**
     * Record a search started event.
     */
    public function recordSearchStarted(BrimSearchStarted $event): void
    {
        if (!$this->shouldTrack('searches')) {
            return;
        }

        $this->store('search.started', [
            'model_class' => $event->modelClass,
            'query_length' => $event->metadata['query_length'],
            'limit' => $event->limit,
            'min_similarity' => $event->minSimilarity,
            'namespace' => $event->namespace,
        ]);

        $this->log('Search started', [
            'model_class' => class_basename($event->modelClass),
            'query_preview' => substr($event->query, 0, 50) . '...',
        ]);
    }

    /**
     * Record a search completed event.
     */
    public function recordSearchCompleted(BrimSearchCompleted $event): void
    {
        if (!$this->shouldTrack('searches')) {
            return;
        }

        $this->store('search.completed', [
            'model_class' => $event->modelClass,
            'query_length' => $event->metadata['query_length'],
            'result_count' => $event->resultCount,
            'total_duration_ms' => $event->totalDuration,
            'embedding_time_ms' => $event->embeddingTime,
            'search_time_ms' => $event->searchTime,
            'hydration_time_ms' => $event->hydrationTime,
            'top_score' => $event->topScore,
            'bottom_score' => $event->bottomScore,
            'avg_score' => $event->avgScore,
        ]);

        $this->log('Search completed', [
            'model_class' => class_basename($event->modelClass),
            'results' => $event->resultCount,
            'duration_ms' => $event->totalDuration,
            'top_score' => $event->topScore,
        ]);
    }

    /**
     * Record a batch started event.
     */
    public function recordBatchStarted(BrimBatchStarted $event): void
    {
        if (!$this->shouldTrack('batches')) {
            return;
        }

        $this->store('batch.started', [
            'model_class' => $event->modelClass,
            'total_count' => $event->totalCount,
            'operation' => $event->operation,
        ]);

        $this->log('Batch started', [
            'model_class' => class_basename($event->modelClass),
            'count' => $event->totalCount,
            'operation' => $event->operation,
        ]);
    }

    /**
     * Record a batch completed event.
     */
    public function recordBatchCompleted(BrimBatchCompleted $event): void
    {
        if (!$this->shouldTrack('batches')) {
            return;
        }

        $this->store('batch.completed', [
            'model_class' => $event->modelClass,
            'processed_count' => $event->processedCount,
            'failed_count' => $event->failedCount,
            'operation' => $event->operation,
            'duration_ms' => $event->duration,
            'throughput' => $event->throughput,
        ]);

        $this->log('Batch completed', [
            'model_class' => class_basename($event->modelClass),
            'processed' => $event->processedCount,
            'failed' => $event->failedCount,
            'throughput' => $event->throughput . '/sec',
        ]);
    }

    /**
     * Store a telemetry entry in the database.
     */
    protected function store(string $event, array $data): void
    {
        if (!($this->config['store']['enabled'] ?? true)) {
            return;
        }

        try {
            TelemetryEntry::create([
                'event' => $event,
                'data' => $data,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Silently fail - don't break the main operation
            Log::debug('Brim telemetry store failed: ' . $e->getMessage());
        }
    }

    /**
     * Log a telemetry message.
     */
    protected function log(string $message, array $context = [], string $level = 'debug'): void
    {
        if (!($this->config['logging']['enabled'] ?? false)) {
            return;
        }

        $channel = $this->config['logging']['channel'] ?? null;
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->{$level}("[Brim] {$message}", $context);
    }

    /**
     * Get aggregated statistics.
     */
    public function getStats(string $period = '24h'): array
    {
        $since = match ($period) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay(),
        };

        $entries = TelemetryEntry::where('occurred_at', '>=', $since)->get();

        return [
            'period' => $period,
            'since' => $since->toIso8601String(),
            'embeddings' => $this->aggregateEmbeddings($entries),
            'searches' => $this->aggregateSearches($entries),
            'batches' => $this->aggregateBatches($entries),
            'failures' => $this->aggregateFailures($entries),
        ];
    }

    /**
     * Get recent telemetry entries.
     */
    public function getRecent(int $limit = 50, ?string $eventFilter = null): \Illuminate\Support\Collection
    {
        $query = TelemetryEntry::orderByDesc('occurred_at')->limit($limit);

        if ($eventFilter) {
            $query->where('event', 'like', "{$eventFilter}%");
        }

        return $query->get();
    }

    /**
     * Prune old telemetry entries.
     */
    public function prune(?int $days = null): int
    {
        $days = $days ?? ($this->config['store']['retention_days'] ?? 30);
        $cutoff = now()->subDays($days);

        return TelemetryEntry::where('occurred_at', '<', $cutoff)->delete();
    }

    /**
     * Get timing breakdown for debug mode.
     */
    public function getDebugTimings(): array
    {
        if (!($this->config['debug'] ?? false)) {
            return [];
        }

        $recent = TelemetryEntry::where('event', 'search.completed')
            ->orderByDesc('occurred_at')
            ->first();

        if (!$recent) {
            return [];
        }

        return [
            'total_ms' => $recent->data['total_duration_ms'] ?? 0,
            'embedding_ms' => $recent->data['embedding_time_ms'] ?? 0,
            'search_ms' => $recent->data['search_time_ms'] ?? 0,
            'hydration_ms' => $recent->data['hydration_time_ms'] ?? 0,
        ];
    }

    protected function aggregateEmbeddings($entries): array
    {
        $completed = $entries->where('event', 'embedding.completed');

        if ($completed->isEmpty()) {
            return [
                'count' => 0,
                'avg_duration_ms' => 0,
                'total_chunks' => 0,
                'avg_embedding_time_ms' => 0,
                'avg_storage_time_ms' => 0,
                'min_duration_ms' => 0,
                'max_duration_ms' => 0,
            ];
        }

        $durations = $completed->map(fn($e) => $e->data['duration_ms'] ?? 0);
        $embeddingTimes = $completed->map(fn($e) => $e->data['embedding_time_ms'] ?? 0);
        $storageTimes = $completed->map(fn($e) => $e->data['storage_time_ms'] ?? 0);
        $chunks = $completed->map(fn($e) => $e->data['chunk_count'] ?? 0);

        return [
            'count' => $completed->count(),
            'avg_duration_ms' => round($durations->avg(), 2),
            'min_duration_ms' => round($durations->min(), 2),
            'max_duration_ms' => round($durations->max(), 2),
            'total_chunks' => $chunks->sum(),
            'avg_embedding_time_ms' => round($embeddingTimes->avg(), 2),
            'avg_storage_time_ms' => round($storageTimes->avg(), 2),
        ];
    }

    protected function aggregateSearches($entries): array
    {
        $completed = $entries->where('event', 'search.completed');

        if ($completed->isEmpty()) {
            return [
                'count' => 0,
                'avg_duration_ms' => 0,
                'avg_results' => 0,
                'avg_top_score' => 0,
                'avg_embedding_time_ms' => 0,
                'avg_search_time_ms' => 0,
                'avg_hydration_time_ms' => 0,
                'min_duration_ms' => 0,
                'max_duration_ms' => 0,
            ];
        }

        $durations = $completed->map(fn($e) => $e->data['total_duration_ms'] ?? 0);
        $results = $completed->map(fn($e) => $e->data['result_count'] ?? 0);
        $topScores = $completed->map(fn($e) => $e->data['top_score'] ?? 0);
        $embeddingTimes = $completed->map(fn($e) => $e->data['embedding_time_ms'] ?? 0);
        $searchTimes = $completed->map(fn($e) => $e->data['search_time_ms'] ?? 0);
        $hydrationTimes = $completed->map(fn($e) => $e->data['hydration_time_ms'] ?? 0);

        return [
            'count' => $completed->count(),
            'avg_duration_ms' => round($durations->avg(), 2),
            'min_duration_ms' => round($durations->min(), 2),
            'max_duration_ms' => round($durations->max(), 2),
            'avg_results' => round($results->avg(), 1),
            'avg_top_score' => round($topScores->avg(), 4),
            'avg_embedding_time_ms' => round($embeddingTimes->avg(), 2),
            'avg_search_time_ms' => round($searchTimes->avg(), 2),
            'avg_hydration_time_ms' => round($hydrationTimes->avg(), 2),
        ];
    }

    protected function aggregateBatches($entries): array
    {
        $completed = $entries->where('event', 'batch.completed');

        if ($completed->isEmpty()) {
            return [
                'count' => 0,
                'total_processed' => 0,
                'total_failed' => 0,
                'avg_throughput' => 0,
                'avg_duration_ms' => 0,
            ];
        }

        $processed = $completed->map(fn($e) => $e->data['processed_count'] ?? 0);
        $failed = $completed->map(fn($e) => $e->data['failed_count'] ?? 0);
        $throughput = $completed->map(fn($e) => $e->data['throughput'] ?? 0);
        $durations = $completed->map(fn($e) => $e->data['duration_ms'] ?? 0);

        return [
            'count' => $completed->count(),
            'total_processed' => $processed->sum(),
            'total_failed' => $failed->sum(),
            'avg_throughput' => round($throughput->avg(), 2),
            'avg_duration_ms' => round($durations->avg(), 2),
        ];
    }

    protected function aggregateFailures($entries): array
    {
        $failed = $entries->where('event', 'embedding.failed');

        $byError = [];
        foreach ($failed as $entry) {
            $errorClass = $entry->data['error_class'] ?? 'Unknown';
            $byError[$errorClass] = ($byError[$errorClass] ?? 0) + 1;
        }

        return [
            'count' => $failed->count(),
            'by_error' => $byError,
        ];
    }
}
