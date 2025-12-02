<?php

namespace Brim\Stores;

use Brim\Contracts\VectorStore;
use Brim\Models\Embedding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PgVectorStore implements VectorStore
{
    protected string $table;
    protected ?string $connection;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('brim.vector_store', []);

        $this->table = $config['table'] ?? 'brim_embeddings';
        $this->connection = $config['connection'] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function store(Model $model, array $vectors, string $embeddingModel, ?string $namespace = null): void
    {
        $modelType = get_class($model);
        $modelId = $model->getKey();

        // Delete existing embeddings for this model
        $this->delete($model);

        // Generate content hash from model's embeddable content
        $contentHash = null;
        if (method_exists($model, 'toEmbeddableText')) {
            $contentHash = hash('sha256', $model->toEmbeddableText());
        }

        // Insert new embeddings
        foreach ($vectors as $chunkIndex => $vector) {
            Embedding::on($this->connection)->create([
                'model_type' => $modelType,
                'model_id' => $modelId,
                'chunk_index' => $chunkIndex,
                'namespace' => $namespace,
                'embedding_model' => $embeddingModel,
                'content_hash' => $contentHash,
                'embedding' => $this->formatVector($vector),
            ]);
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(Model $model): void
    {
        Embedding::on($this->connection)
            ->where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->delete();
    }

    /**
     * @inheritDoc
     */
    public function exists(Model $model): bool
    {
        return Embedding::on($this->connection)
            ->where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->exists();
    }

    /**
     * @inheritDoc
     */
    public function chunkCount(Model $model): int
    {
        return Embedding::on($this->connection)
            ->where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->count();
    }

    /**
     * @inheritDoc
     */
    public function search(
        string $modelClass,
        array $queryVector,
        int $limit = 10,
        float $minSimilarity = 0.0,
        ?string $namespace = null
    ): Collection {
        $vectorString = $this->formatVector($queryVector);

        $query = DB::connection($this->connection)
            ->table($this->table)
            ->select([
                'model_id',
                'chunk_index',
                DB::raw("1 - (embedding <=> '{$vectorString}'::vector) as similarity"),
            ])
            ->where('model_type', $modelClass);

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        if ($minSimilarity > 0) {
            $query->whereRaw("1 - (embedding <=> '{$vectorString}'::vector) >= ?", [$minSimilarity]);
        }

        // Get all matching chunks, ordered by similarity
        $results = $query
            ->orderByDesc('similarity')
            ->get();

        // Deduplicate by model_id, keeping the best chunk score for each model
        $deduplicated = $results
            ->groupBy('model_id')
            ->map(function ($chunks) {
                return $chunks->first(); // Already ordered by similarity, first is best
            })
            ->values()
            ->take($limit);

        return $deduplicated;
    }

    /**
     * @inheritDoc
     */
    public function stats(): array
    {
        $total = Embedding::on($this->connection)->count();

        $byType = Embedding::on($this->connection)
            ->selectRaw('model_type, COUNT(*) as count, COUNT(DISTINCT model_id) as models')
            ->groupBy('model_type')
            ->get()
            ->keyBy('model_type')
            ->map(fn($row) => [
                'embeddings' => $row->count,
                'models' => $row->models,
            ])
            ->toArray();

        $uniqueModels = Embedding::on($this->connection)
            ->selectRaw('COUNT(DISTINCT CONCAT(model_type, model_id)) as count')
            ->value('count');

        return [
            'total' => $total,
            'models' => (int) $uniqueModels,
            'by_type' => $byType,
        ];
    }

    /**
     * Find similar models based on embedding similarity.
     *
     * @param Model $model
     * @param int $limit
     * @return Collection
     */
    public function findSimilar(Model $model, int $limit = 5): Collection
    {
        $modelType = get_class($model);
        $modelId = $model->getKey();

        // Get the embedding for this model (first chunk)
        $sourceEmbedding = Embedding::on($this->connection)
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->where('chunk_index', 0)
            ->first();

        if (!$sourceEmbedding) {
            return collect();
        }

        // Find similar embeddings (excluding the source model)
        $results = DB::connection($this->connection)
            ->table($this->table)
            ->select([
                'model_id',
                DB::raw("1 - (embedding <=> '{$sourceEmbedding->embedding}'::vector) as similarity"),
            ])
            ->where('model_type', $modelType)
            ->where('model_id', '!=', $modelId)
            ->where('chunk_index', 0)  // Compare first chunks only
            ->orderByDesc('similarity')
            ->limit($limit)
            ->get();

        // Load the actual models
        $modelIds = $results->pluck('model_id')->toArray();
        $similarities = $results->pluck('similarity', 'model_id')->toArray();

        if (empty($modelIds)) {
            return collect();
        }

        $models = (new $modelType)
            ->whereIn((new $modelType)->getKeyName(), $modelIds)
            ->get()
            ->each(function ($model) use ($similarities) {
                $model->similarity = $similarities[$model->getKey()] ?? 0;
            })
            ->sortByDesc('similarity')
            ->values();

        return $models;
    }

    /**
     * Find orphaned embeddings (where the model no longer exists).
     *
     * @return Collection
     */
    public function findOrphaned(): Collection
    {
        $embeddings = Embedding::on($this->connection)
            ->select('model_type', 'model_id')
            ->distinct()
            ->get();

        $orphaned = collect();

        foreach ($embeddings as $embedding) {
            $modelClass = $embedding->model_type;

            if (!class_exists($modelClass)) {
                $orphaned->push($embedding);
                continue;
            }

            $exists = (new $modelClass)->newQuery()
                ->where((new $modelClass)->getKeyName(), $embedding->model_id)
                ->exists();

            if (!$exists) {
                $orphaned->push($embedding);
            }
        }

        return $orphaned;
    }

    /**
     * Delete orphaned embeddings.
     *
     * @return int Number of deleted embeddings
     */
    public function deleteOrphaned(): int
    {
        $orphaned = $this->findOrphaned();
        $deleted = 0;

        foreach ($orphaned as $embedding) {
            $deleted += Embedding::on($this->connection)
                ->where('model_type', $embedding->model_type)
                ->where('model_id', $embedding->model_id)
                ->delete();
        }

        return $deleted;
    }

    /**
     * Format a vector array for PostgreSQL.
     *
     * @param array<float> $vector
     * @return string
     */
    protected function formatVector(array $vector): string
    {
        return '[' . implode(',', $vector) . ']';
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the connection name.
     *
     * @return string|null
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }
}
