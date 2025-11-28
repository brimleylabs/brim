<?php

namespace Brim\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BrimBatchCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public float $duration;
    public float $throughput;

    public function __construct(
        public string $modelClass,
        public int $processedCount,
        public int $failedCount,
        public string $operation,
        float $startTime,
        public array $metadata = []
    ) {
        $endTime = microtime(true);
        $this->duration = round(($endTime - $startTime) * 1000, 2);
        $this->throughput = $this->duration > 0
            ? round($processedCount / ($this->duration / 1000), 2)
            : 0;

        $this->metadata['timestamp'] = $endTime;
    }
}
