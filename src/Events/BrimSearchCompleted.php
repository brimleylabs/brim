<?php

namespace Brim\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class BrimSearchCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public float $totalDuration;
    public float $embeddingTime;
    public float $searchTime;
    public float $hydrationTime;
    public int $resultCount;
    public ?float $topScore;
    public ?float $bottomScore;
    public float $avgScore;

    public function __construct(
        public string $modelClass,
        public string $query,
        Collection $results,
        float $startTime,
        float $embeddingEndTime,
        float $searchEndTime,
        public array $metadata = []
    ) {
        $endTime = microtime(true);

        $this->totalDuration = round(($endTime - $startTime) * 1000, 2);
        $this->embeddingTime = round(($embeddingEndTime - $startTime) * 1000, 2);
        $this->searchTime = round(($searchEndTime - $embeddingEndTime) * 1000, 2);
        $this->hydrationTime = round(($endTime - $searchEndTime) * 1000, 2);

        $this->resultCount = $results->count();

        $scores = $results->pluck('similarity_score')->filter();
        $this->topScore = $scores->first();
        $this->bottomScore = $scores->last();
        $this->avgScore = $scores->count() > 0 ? round($scores->avg(), 4) : 0;

        $this->metadata['timestamp'] = $endTime;
        $this->metadata['query_length'] = strlen($query);
    }
}
