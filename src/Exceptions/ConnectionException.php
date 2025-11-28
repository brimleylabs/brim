<?php

namespace Brim\Exceptions;

class ConnectionException extends BrimException
{
    protected string $host;
    protected ?int $statusCode;

    public function __construct(string $message, string $host = '', ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->host = $host;
        $this->statusCode = $statusCode;
    }

    public static function ollamaUnavailable(string $host, ?\Throwable $previous = null): static
    {
        return new static(
            "Could not connect to Ollama at [{$host}]. Is Ollama running?",
            $host,
            null,
            $previous
        );
    }

    public static function openAiFailed(int $statusCode, string $message): static
    {
        return new static(
            "OpenAI API request failed with status {$statusCode}: {$message}",
            'api.openai.com',
            $statusCode
        );
    }

    public static function timeout(string $host, int $timeout): static
    {
        return new static(
            "Connection to [{$host}] timed out after {$timeout} seconds.",
            $host
        );
    }

    public static function rateLimited(string $driver, ?int $retryAfter = null): static
    {
        $message = "Rate limited by [{$driver}]";
        if ($retryAfter) {
            $message .= ". Retry after {$retryAfter} seconds.";
        }
        return new static($message, '', 429);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function isRetryable(): bool
    {
        return $this->statusCode === null
            || $this->statusCode === 429
            || $this->statusCode >= 500;
    }
}
