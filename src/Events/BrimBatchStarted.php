<?php

namespace Brim\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BrimBatchStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $modelClass,
        public int $totalCount,
        public string $operation, // 'embed', 'delete', 'reindex'
        public array $metadata = []
    ) {
        $this->metadata['timestamp'] = microtime(true);
    }
}
