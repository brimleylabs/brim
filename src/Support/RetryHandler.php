<?php

namespace Brim\Support;

use Brim\Exceptions\ConnectionException;
use Closure;
use Throwable;

class RetryHandler
{
    protected int $maxAttempts;
    protected int $baseDelayMs;
    protected int $maxDelayMs;
    protected float $multiplier;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('brim.retry', []);

        $this->maxAttempts = $config['max_attempts'] ?? 3;
        $this->baseDelayMs = $config['base_delay_ms'] ?? 200;
        $this->maxDelayMs = $config['max_delay_ms'] ?? 5000;
        $this->multiplier = $config['multiplier'] ?? 2.0;
    }

    /**
     * Execute a callback with retry logic.
     *
     * @param Closure $callback
     * @param string $operation
     * @return mixed
     * @throws ConnectionException
     */
    public function execute(Closure $callback, string $operation): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            try {
                return $callback();
            } catch (ConnectionException $e) {
                $lastException = $e;

                if (!$e->isRetryable() || $attempt >= $this->maxAttempts - 1) {
                    throw $e;
                }

                $this->sleep($attempt);
                $attempt++;
            } catch (Throwable $e) {
                // Wrap non-connection exceptions and don't retry
                throw new ConnectionException(
                    "Operation [{$operation}] failed: " . $e->getMessage(),
                    '',
                    null,
                    $e
                );
            }
        }

        throw $lastException ?? new ConnectionException("Operation [{$operation}] failed after {$this->maxAttempts} attempts.");
    }

    /**
     * Calculate delay and sleep.
     *
     * @param int $attempt
     * @return void
     */
    protected function sleep(int $attempt): void
    {
        $delay = $this->calculateDelay($attempt);
        usleep($delay * 1000);
    }

    /**
     * Calculate delay with exponential backoff and jitter.
     *
     * @param int $attempt
     * @return int Delay in milliseconds
     */
    protected function calculateDelay(int $attempt): int
    {
        // Exponential backoff
        $delay = (int) ($this->baseDelayMs * pow($this->multiplier, $attempt));

        // Cap at max delay
        $delay = min($delay, $this->maxDelayMs);

        // Add 0-25% jitter to prevent thundering herd
        $jitter = (int) ($delay * (mt_rand(0, 25) / 100));

        return $delay + $jitter;
    }

    /**
     * Get the maximum number of attempts.
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
