<?php

namespace Brim\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BrimEmbeddingStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Model $model,
        public string $driver,
        public int $textLength,
        public bool $queued = false,
        public ?float $queueWaitTime = null,
        public array $metadata = []
    ) {
        $this->metadata['model_type'] = get_class($model);
        $this->metadata['model_id'] = $model->getKey();
        $this->metadata['timestamp'] = microtime(true);
    }
}
