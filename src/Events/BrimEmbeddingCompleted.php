<?php

namespace Brim\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BrimEmbeddingCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public float $duration;
    public float $embeddingTime;
    public float $storageTime;

    public function __construct(
        public Model $model,
        public string $driver,
        public int $chunkCount,
        public int $dimensions,
        float $startTime,
        float $embeddingEndTime,
        public array $metadata = []
    ) {
        $endTime = microtime(true);
        $this->duration = round(($endTime - $startTime) * 1000, 2);
        $this->embeddingTime = round(($embeddingEndTime - $startTime) * 1000, 2);
        $this->storageTime = round(($endTime - $embeddingEndTime) * 1000, 2);

        $this->metadata['model_type'] = get_class($model);
        $this->metadata['model_id'] = $model->getKey();
        $this->metadata['timestamp'] = $endTime;
    }
}
