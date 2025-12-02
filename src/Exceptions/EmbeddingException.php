<?php

namespace Brim\Exceptions;

class EmbeddingException extends BrimException
{
    public static function generationFailed(string $reason): static
    {
        return new static("Failed to generate embedding: {$reason}");
    }

    public static function invalidResponse(string $driver, string $details = ''): static
    {
        $message = "Invalid response from [{$driver}] driver";
        if ($details) {
            $message .= ": {$details}";
        }
        return new static($message);
    }

    public static function modelNotAvailable(string $model, string $driver): static
    {
        return new static("Model [{$model}] is not available on [{$driver}] driver.");
    }

    public static function emptyInput(): static
    {
        return new static("Cannot generate embedding for empty input.");
    }

    public static function dimensionMismatch(int $expected, int $actual): static
    {
        return new static("Embedding dimension mismatch: expected {$expected}, got {$actual}.");
    }
}
