<?php

namespace Brim\Traits;

use Brim\Brim;
use Brim\Contracts\Embeddable;
use Brim\Models\Embedding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;

/**
 * Trait HasEmbeddings
 *
 * Add this trait to Eloquent models that should have embeddings generated.
 *
 * @property array $embeddable Fields to include in embedding (supports dot notation for relationships)
 * @property string|null $brimNamespace Namespace template with {attribute} placeholders
 * @property bool $brimAutoSync Whether to auto-sync embeddings on save (default: true)
 */
trait HasEmbeddings
{
    /**
     * Search context for query scopes.
     *
     * @var array
     */
    protected array $embeddingSearchContext = [];

    /**
     * Boot the HasEmbeddings trait.
     *
     * @return void
     */
    public static function bootHasEmbeddings(): void
    {
        static::saved(function ($model) {
            // Check model-level and global auto_sync settings
            $modelAutoSync = $model->brimAutoSync ?? true;
            $globalAutoSync = config('brim.auto_sync', true);

            if ($modelAutoSync && $globalAutoSync) {
                $model->queueOrGenerateEmbedding();
            }
        });

        static::deleted(function ($model) {
            app(Brim::class)->deleteFor($model);
        });
    }

    /**
     * Relationship to embeddings.
     *
     * @return MorphMany
     */
    public function embeddings(): MorphMany
    {
        return $this->morphMany(Embedding::class, 'model');
    }

    /**
     * Get the text content to be embedded.
     *
     * @return string
     */
    public function toEmbeddable(): string
    {
        $fields = $this->embeddable ?? [];
        $parts = [];

        foreach ($fields as $field) {
            $value = $this->getEmbeddableValue($field);
            if (!empty($value)) {
                $parts[] = $value;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Get value for an embeddable field, supporting dot notation.
     *
     * @param string $field
     * @return string
     */
    protected function getEmbeddableValue(string $field): string
    {
        // Check for dot notation (relationship)
        if (str_contains($field, '.')) {
            return $this->getRelationshipEmbeddableValue($field);
        }

        $value = $this->getAttribute($field);

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return (string) ($value ?? '');
    }

    /**
     * Get value from a relationship using dot notation.
     *
     * @param string $field
     * @return string
     */
    protected function getRelationshipEmbeddableValue(string $field): string
    {
        $parts = explode('.', $field);
        $relationName = array_shift($parts);
        $attribute = implode('.', $parts);

        $relation = $this->$relationName;

        if ($relation === null) {
            return '';
        }

        // Handle collection of related models
        if ($relation instanceof \Illuminate\Support\Collection || is_iterable($relation)) {
            $values = [];
            foreach ($relation as $item) {
                $value = Arr::get($item, $attribute);
                if ($value !== null) {
                    $values[] = $value;
                }
            }
            return implode(', ', $values);
        }

        // Handle single related model
        return (string) (Arr::get($relation, $attribute) ?? '');
    }

    /**
     * Get the namespace for this model's embeddings.
     *
     * @return string|null
     */
    public function getEmbeddingNamespace(): ?string
    {
        $template = $this->brimNamespace ?? null;

        if ($template === null) {
            return null;
        }

        // Replace {attribute} placeholders
        return preg_replace_callback('/\{(\w+)\}/', function ($matches) {
            return (string) ($this->getAttribute($matches[1]) ?? '');
        }, $template);
    }

    /**
     * Queue or immediately generate embedding based on config.
     *
     * @return void
     */
    public function queueOrGenerateEmbedding(): void
    {
        app(Brim::class)->generateOrQueueFor($this);
    }

    /**
     * Generate embedding immediately.
     *
     * @return void
     */
    public function generateEmbedding(): void
    {
        app(Brim::class)->generateFor($this);
    }

    /**
     * Delete embedding for this model.
     *
     * @return void
     */
    public function deleteEmbedding(): void
    {
        app(Brim::class)->deleteFor($this);
    }

    /**
     * Check if this model has an embedding.
     *
     * @return bool
     */
    public function hasEmbedding(): bool
    {
        return app(Brim::class)->existsFor($this);
    }

    /**
     * Get the number of embedding chunks for this model.
     *
     * @return int
     */
    public function embeddingChunkCount(): int
    {
        return app(Brim::class)->chunkCountFor($this);
    }

    /**
     * Find similar models based on embedding similarity.
     *
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function findSimilar(int $limit = 5): \Illuminate\Support\Collection
    {
        return app(Brim::class)->findSimilar($this, $limit);
    }

    /**
     * Scope for semantic search.
     *
     * @param Builder $query
     * @param string $searchQuery
     * @param int|null $limit
     * @return Builder
     */
    public function scopeSemanticSearch(Builder $query, string $searchQuery, ?int $limit = null): Builder
    {
        $limit = $limit ?? config('brim.search.limit', 10);
        $minSimilarity = $this->embeddingSearchContext['min_similarity'] ?? config('brim.search.min_similarity', 0.0);
        $namespace = $this->embeddingSearchContext['namespace'] ?? null;

        // Get search results from Brim
        $results = app(Brim::class)->search(
            static::class,
            $searchQuery,
            $limit,
            $minSimilarity,
            $namespace
        );

        // Get model IDs in order
        $orderedIds = $results->pluck($this->getKeyName())->toArray();

        if (empty($orderedIds)) {
            // Return empty result set
            return $query->whereRaw('1 = 0');
        }

        // Store similarity scores for later access
        $similarityScores = $results->pluck('similarity_score', $this->getKeyName())->toArray();

        // Add whereIn and order by similarity
        $query->whereIn($this->getQualifiedKeyName(), $orderedIds);

        // PostgreSQL compatible ordering by array position
        $ids = implode(',', array_map('intval', $orderedIds));
        $query->orderByRaw("array_position(ARRAY[{$ids}], {$this->getQualifiedKeyName()}::int)");

        // Store scores for access via accessor
        $this->embeddingSearchContext['scores'] = $similarityScores;

        return $query;
    }

    /**
     * Scope to filter by namespace.
     *
     * @param Builder $query
     * @param string $namespace
     * @return Builder
     */
    public function scopeInNamespace(Builder $query, string $namespace): Builder
    {
        $this->embeddingSearchContext['namespace'] = $namespace;
        return $query;
    }

    /**
     * Scope to set minimum similarity threshold.
     *
     * @param Builder $query
     * @param float $threshold
     * @return Builder
     */
    public function scopeMinSimilarity(Builder $query, float $threshold): Builder
    {
        $this->embeddingSearchContext['min_similarity'] = $threshold;
        return $query;
    }
}
