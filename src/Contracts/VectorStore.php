<?php

namespace Brim\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface VectorStore
{
    /**
     * Store embeddings for a model.
     *
     * @param Model $model
     * @param array<array<float>> $vectors
     * @param string $embeddingModel
     * @param string|null $namespace
     * @return void
     */
    public function store(Model $model, array $vectors, string $embeddingModel, ?string $namespace = null): void;

    /**
     * Delete all embeddings for a model.
     *
     * @param Model $model
     * @return void
     */
    public function delete(Model $model): void;

    /**
     * Check if embeddings exist for a model.
     *
     * @param Model $model
     * @return bool
     */
    public function exists(Model $model): bool;

    /**
     * Get the number of embedding chunks for a model.
     *
     * @param Model $model
     * @return int
     */
    public function chunkCount(Model $model): int;

    /**
     * Search for similar embeddings.
     *
     * @param string $modelClass
     * @param array<float> $queryVector
     * @param int $limit
     * @param float $minSimilarity
     * @param string|null $namespace
     * @return Collection
     */
    public function search(
        string $modelClass,
        array $queryVector,
        int $limit = 10,
        float $minSimilarity = 0.0,
        ?string $namespace = null
    ): Collection;

    /**
     * Get statistics about stored embeddings.
     *
     * @return array{total: int, models: int, by_type: array}
     */
    public function stats(): array;
}
