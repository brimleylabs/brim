<?php

namespace Brim\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BrimSearchStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $modelClass,
        public string $query,
        public int $limit,
        public float $minSimilarity,
        public ?string $namespace = null,
        public array $metadata = []
    ) {
        $this->metadata['query_length'] = strlen($query);
        $this->metadata['timestamp'] = microtime(true);
    }
}
