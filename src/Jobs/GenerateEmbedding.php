<?php

namespace Brim\Jobs;

use Brim\Brim;
use Brim\Contracts\Embeddable;
use Brim\Exceptions\BrimException;
use Brim\Exceptions\ConnectionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * The model to generate embeddings for.
     *
     * @var Model&Embeddable
     */
    public Model $model;

    /**
     * Create a new job instance.
     *
     * @param Model&Embeddable $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Execute the job.
     *
     * @param Brim $brim
     * @return void
     * @throws BrimException
     */
    public function handle(Brim $brim): void
    {
        // Check if model still exists
        if (!$this->model->exists) {
            return;
        }

        // Check if model still implements Embeddable
        if (!$this->model instanceof Embeddable) {
            return;
        }

        $brim->generateFor($this->model);
    }

    /**
     * Determine if the job should be retried.
     *
     * @param \Throwable $exception
     * @return bool
     */
    public function shouldRetry(\Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            && $exception->isRetryable();
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // Log the failure for debugging
        logger()->error('Brim embedding generation failed', [
            'model_type' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'brim',
            'brim:' . class_basename($this->model),
            'brim:' . get_class($this->model) . ':' . $this->model->getKey(),
        ];
    }

    /**
     * Get the display name for the job.
     *
     * @return string
     */
    public function displayName(): string
    {
        return 'Brim: Generate embedding for ' . class_basename($this->model) . ' #' . $this->model->getKey();
    }
}
