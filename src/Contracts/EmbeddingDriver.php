<?php

namespace Brim\Contracts;

interface EmbeddingDriver
{
    /**
     * Generate an embedding for a single text.
     *
     * @param string $text
     * @return array<float>
     */
    public function embed(string $text): array;

    /**
     * Generate embeddings for multiple texts.
     *
     * @param array<string> $texts
     * @return array<array<float>>
     */
    public function embedBatch(array $texts): array;

    /**
     * Get the embedding dimensions for the current model.
     *
     * @return int
     */
    public function dimensions(): int;

    /**
     * Get the token limit for the current model.
     *
     * @return int
     */
    public function tokenLimit(): int;

    /**
     * Get the model name being used.
     *
     * @return string
     */
    public function modelName(): string;

    /**
     * Check the health/availability of the embedding service.
     *
     * @return array{healthy: bool, message: string, details: array}
     */
    public function healthCheck(): array;
}
