<?php

namespace Brim\Support;

class TextChunker
{
    /**
     * Model specifications: [model => [dimensions, token_limit]]
     */
    protected const MODEL_SPECS = [
        // Ollama models
        'nomic-embed-text' => ['dimensions' => 768, 'token_limit' => 8192],
        'all-minilm' => ['dimensions' => 384, 'token_limit' => 256],
        'mxbai-embed-large' => ['dimensions' => 1024, 'token_limit' => 512],

        // OpenAI models
        'text-embedding-3-small' => ['dimensions' => 1536, 'token_limit' => 8191],
        'text-embedding-3-large' => ['dimensions' => 3072, 'token_limit' => 8191],
        'text-embedding-ada-002' => ['dimensions' => 1536, 'token_limit' => 8191],
    ];

    protected int $overlapWords;
    protected bool $enabled;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('brim.chunking', []);

        $this->enabled = $config['enabled'] ?? true;
        $this->overlapWords = $config['overlap_words'] ?? 50;
    }

    /**
     * Split text into chunks based on model's token limit.
     *
     * @param string $text
     * @param string $model
     * @return array<string>
     */
    public function chunk(string $text, string $model): array
    {
        if (!$this->enabled || !$this->needsChunking($text, $model)) {
            return [$text];
        }

        $tokenLimit = $this->getTokenLimit($model);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return [$text];
        }

        // Estimate words per chunk (rough: 1 token ≈ 0.75 words for English)
        $wordsPerChunk = (int) ($tokenLimit * 0.75 * 0.9); // 90% of limit for safety
        $wordsPerChunk = max($wordsPerChunk, 100); // Minimum 100 words per chunk

        $chunks = [];
        $currentPosition = 0;
        $totalWords = count($words);

        while ($currentPosition < $totalWords) {
            $chunkWords = array_slice($words, $currentPosition, $wordsPerChunk);
            $chunks[] = implode(' ', $chunkWords);

            // Move position, accounting for overlap
            $currentPosition += $wordsPerChunk - $this->overlapWords;

            // Prevent infinite loop
            if ($currentPosition <= 0 && count($chunks) > 0) {
                $currentPosition = $wordsPerChunk;
            }
        }

        return $chunks;
    }

    /**
     * Check if text needs to be chunked.
     *
     * @param string $text
     * @param string $model
     * @return bool
     */
    public function needsChunking(string $text, string $model): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $estimatedTokens = $this->estimateTokens($text);
        $tokenLimit = $this->getTokenLimit($model);

        return $estimatedTokens > ($tokenLimit * 0.9); // 90% threshold
    }

    /**
     * Estimate the number of tokens in a text.
     * Uses a rough approximation: ~4 characters per token for English.
     *
     * @param string $text
     * @return int
     */
    public function estimateTokens(string $text): int
    {
        // Average ~4 characters per token for English text
        // This is a rough approximation; actual tokenization varies by model
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Get the token limit for a model.
     *
     * @param string $model
     * @return int
     */
    public function getTokenLimit(string $model): int
    {
        return self::MODEL_SPECS[$model]['token_limit'] ?? 8192;
    }

    /**
     * Get the dimensions for a model.
     *
     * @param string $model
     * @return int
     */
    public function getDimensions(string $model): int
    {
        return self::MODEL_SPECS[$model]['dimensions'] ?? 768;
    }

    /**
     * Check if a model is known.
     *
     * @param string $model
     * @return bool
     */
    public function isKnownModel(string $model): bool
    {
        return isset(self::MODEL_SPECS[$model]);
    }

    /**
     * Get all known model specifications.
     *
     * @return array
     */
    public function getModelSpecs(): array
    {
        return self::MODEL_SPECS;
    }
}
