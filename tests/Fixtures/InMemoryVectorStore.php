<?php

namespace Brim\Tests\Fixtures;

use Brim\Contracts\VectorStore;
use Illuminate\Support\Collection;

/**
 * Simple in-memory vector store for testing.
 *
 * @group integration
 */
class InMemoryVectorStore implements VectorStore
{
    private array $embeddings = [];

    public function store($model, array $vectors, string $modelName, ?string $namespace = null): void
    {
        $key = get_class($model) . ':' . $model->getKey();
        $this->embeddings[$key] = [
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'vectors' => $vectors,
            'model_name' => $modelName,
            'namespace' => $namespace,
        ];
    }

    public function delete($model): void
    {
        $key = get_class($model) . ':' . $model->getKey();
        unset($this->embeddings[$key]);
    }

    public function exists($model): bool
    {
        $key = get_class($model) . ':' . $model->getKey();
        return isset($this->embeddings[$key]);
    }

    public function chunkCount($model): int
    {
        $key = get_class($model) . ':' . $model->getKey();
        return isset($this->embeddings[$key]) ? count($this->embeddings[$key]['vectors']) : 0;
    }

    public function search(
        string $modelClass,
        array $queryVector,
        int $limit = 10,
        float $minSimilarity = 0.0,
        ?string $namespace = null
    ): Collection {
        $results = [];

        foreach ($this->embeddings as $key => $data) {
            if ($data['model_type'] !== $modelClass) {
                continue;
            }

            if ($namespace !== null && $data['namespace'] !== $namespace) {
                continue;
            }

            // Calculate cosine similarity with first vector
            $similarity = $this->cosineSimilarity($queryVector, $data['vectors'][0]);

            if ($similarity >= $minSimilarity) {
                $results[] = [
                    'model_id' => $data['model_id'],
                    'similarity' => $similarity,
                ];
            }
        }

        // Sort by similarity descending
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return collect(array_slice($results, 0, $limit));
    }

    public function findSimilar($model, int $limit): Collection
    {
        $key = get_class($model) . ':' . $model->getKey();

        if (!isset($this->embeddings[$key])) {
            return collect();
        }

        $sourceVector = $this->embeddings[$key]['vectors'][0];
        $results = [];

        foreach ($this->embeddings as $otherKey => $data) {
            if ($otherKey === $key) {
                continue; // Skip self
            }

            if ($data['model_type'] !== get_class($model)) {
                continue;
            }

            $similarity = $this->cosineSimilarity($sourceVector, $data['vectors'][0]);
            $results[] = [
                'model_id' => $data['model_id'],
                'similarity' => $similarity,
            ];
        }

        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        $modelClass = get_class($model);
        return collect(array_slice($results, 0, $limit))->map(function ($result) use ($modelClass) {
            $instance = $modelClass::find($result['model_id']);
            if ($instance) {
                $instance->similarity_score = $result['similarity'];
            }
            return $instance;
        })->filter();
    }

    public function stats(): array
    {
        $total = count($this->embeddings);
        $byType = [];

        foreach ($this->embeddings as $data) {
            $type = $data['model_type'];
            if (!isset($byType[$type])) {
                $byType[$type] = ['models' => 0, 'embeddings' => 0];
            }
            $byType[$type]['models']++;
            $byType[$type]['embeddings'] += count($data['vectors']);
        }

        return [
            'total' => $total,
            'models' => $total,
            'by_type' => $byType,
        ];
    }

    /**
     * Clear all stored embeddings (for test cleanup).
     */
    public function clear(): void
    {
        $this->embeddings = [];
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0;
        $normA = 0;
        $normB = 0;

        $count = count($a);
        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0;
        }

        return $dotProduct / ($normA * $normB);
    }
}
