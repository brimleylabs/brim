<?php

namespace Brim\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BrimEmbeddingFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public float $duration;

    public function __construct(
        public Model $model,
        public string $driver,
        public Throwable $exception,
        public int $retryAttempt = 0,
        float $startTime = 0,
        public array $metadata = []
    ) {
        $this->duration = $startTime > 0 ? round((microtime(true) - $startTime) * 1000, 2) : 0;
        $this->metadata['model_type'] = get_class($model);
        $this->metadata['model_id'] = $model->getKey();
        $this->metadata['error_class'] = get_class($exception);
        $this->metadata['error_message'] = $exception->getMessage();
        $this->metadata['timestamp'] = microtime(true);
    }
}
