<?php

namespace Brim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class TelemetryEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event',
        'data',
        'occurred_at',
    ];

    protected $casts = [
        'data' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('brim.telemetry.store.table', 'brim_telemetry');
    }

    /**
     * Scope to filter by event type prefix.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event', 'like', "{$type}%");
    }

    /**
     * Scope to filter by time range.
     */
    public function scopeSince(Builder $query, $datetime): Builder
    {
        return $query->where('occurred_at', '>=', $datetime);
    }

    /**
     * Scope to get embedding events only.
     */
    public function scopeEmbeddings(Builder $query): Builder
    {
        return $query->ofType('embedding.');
    }

    /**
     * Scope to get search events only.
     */
    public function scopeSearches(Builder $query): Builder
    {
        return $query->ofType('search.');
    }

    /**
     * Scope to get batch events only.
     */
    public function scopeBatches(Builder $query): Builder
    {
        return $query->ofType('batch.');
    }

    /**
     * Scope to get failure events only.
     */
    public function scopeFailures(Builder $query): Builder
    {
        return $query->where('event', 'like', '%.failed');
    }

    /**
     * Get a specific value from the data JSON.
     */
    public function getDataValue(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Get human-readable event name.
     */
    public function getEventNameAttribute(): string
    {
        return match ($this->event) {
            'embedding.started' => 'Embedding Started',
            'embedding.completed' => 'Embedding Completed',
            'embedding.failed' => 'Embedding Failed',
            'search.started' => 'Search Started',
            'search.completed' => 'Search Completed',
            'batch.started' => 'Batch Started',
            'batch.completed' => 'Batch Completed',
            default => ucwords(str_replace('.', ' ', $this->event)),
        };
    }

    /**
     * Get the event category (embedding, search, batch).
     */
    public function getCategoryAttribute(): string
    {
        return explode('.', $this->event)[0] ?? 'unknown';
    }

    /**
     * Check if this is a failure event.
     */
    public function isFailure(): bool
    {
        return str_ends_with($this->event, '.failed');
    }

    /**
     * Get duration in milliseconds if available.
     */
    public function getDurationMsAttribute(): ?float
    {
        $data = $this->data;
        return $data['duration_ms'] ?? $data['total_duration_ms'] ?? null;
    }

    /**
     * Get model identifier string.
     */
    public function getModelIdentifierAttribute(): ?string
    {
        $data = $this->data;
        if (isset($data['model_type']) && isset($data['model_id'])) {
            return class_basename($data['model_type']) . '#' . $data['model_id'];
        }
        if (isset($data['model_class'])) {
            return class_basename($data['model_class']);
        }
        return null;
    }

    /**
     * Get detailed summary based on event type.
     */
    public function getDetailsSummaryAttribute(): string
    {
        $data = $this->data;

        return match ($this->event) {
            'embedding.started' => sprintf(
                '%s | %d chars | driver: %s',
                $this->model_identifier ?? 'Unknown',
                $data['text_length'] ?? 0,
                $data['driver'] ?? 'unknown'
            ),
            'embedding.completed' => sprintf(
                '%s | %d chunks | %d dims | embed: %.1fms | store: %.1fms',
                $this->model_identifier ?? 'Unknown',
                $data['chunk_count'] ?? 0,
                $data['dimensions'] ?? 0,
                $data['embedding_time_ms'] ?? 0,
                $data['storage_time_ms'] ?? 0
            ),
            'embedding.failed' => sprintf(
                '%s | %s: %s',
                $this->model_identifier ?? 'Unknown',
                class_basename($data['error_class'] ?? 'Error'),
                substr($data['error_message'] ?? 'Unknown error', 0, 50)
            ),
            'search.started' => sprintf(
                '%s | limit: %d | min_sim: %.2f | query: %d chars',
                class_basename($data['model_class'] ?? 'Unknown'),
                $data['limit'] ?? 0,
                $data['min_similarity'] ?? 0,
                $data['query_length'] ?? 0
            ),
            'search.completed' => sprintf(
                '%s | %d results | top: %.3f | embed: %.1fms | search: %.1fms | hydrate: %.1fms',
                class_basename($data['model_class'] ?? 'Unknown'),
                $data['result_count'] ?? 0,
                $data['top_score'] ?? 0,
                $data['embedding_time_ms'] ?? 0,
                $data['search_time_ms'] ?? 0,
                $data['hydration_time_ms'] ?? 0
            ),
            'batch.started' => sprintf(
                '%s | %d models | op: %s',
                class_basename($data['model_class'] ?? 'Unknown'),
                $data['total_count'] ?? 0,
                $data['operation'] ?? 'unknown'
            ),
            'batch.completed' => sprintf(
                '%s | %d processed | %d failed | %.1f/sec',
                class_basename($data['model_class'] ?? 'Unknown'),
                $data['processed_count'] ?? 0,
                $data['failed_count'] ?? 0,
                $data['throughput'] ?? 0
            ),
            default => json_encode($data),
        };
    }
}
