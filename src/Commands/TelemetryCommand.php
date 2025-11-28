<?php

namespace Brim\Commands;

use Brim\Facades\Brim;
use Brim\Models\TelemetryEntry;
use Brim\Telemetry\TelemetryCollector;
use Illuminate\Console\Command;

class TelemetryCommand extends Command
{
    protected $signature = 'brim:telemetry
                            {action=stats : Action to perform (stats, recent, prune)}
                            {--period=24h : Time period for stats (1h, 24h, 7d, 30d)}
                            {--limit=20 : Number of recent entries to show}
                            {--type= : Filter by event type (embedding, search, batch)}
                            {--json : Output as JSON}';

    protected $description = 'View Brim telemetry statistics and recent events';

    public function handle(): int
    {
        if (!config('brim.telemetry.enabled', true)) {
            $this->error('Telemetry is disabled. Enable it in config/brim.php');
            return self::FAILURE;
        }

        $action = $this->argument('action');

        return match ($action) {
            'stats' => $this->showStats(),
            'recent' => $this->showRecent(),
            'prune' => $this->pruneEntries(),
            default => $this->invalidAction($action),
        };
    }

    protected function showStats(): int
    {
        $period = $this->option('period');
        $stats = Brim::telemetryStats($period);

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Brim Telemetry Statistics (last {$period})");
        $this->newLine();

        // Embeddings section
        $this->comment('Embeddings');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Generated', $stats['embeddings']['count'] ?? 0],
                ['Avg Duration', ($stats['embeddings']['avg_duration_ms'] ?? 0) . ' ms'],
                ['Avg Embedding Time', ($stats['embeddings']['avg_embedding_time_ms'] ?? 0) . ' ms'],
                ['Avg Storage Time', ($stats['embeddings']['avg_storage_time_ms'] ?? 0) . ' ms'],
                ['Total Chunks', $stats['embeddings']['total_chunks'] ?? 0],
            ]
        );
        $this->newLine();

        // Searches section
        $this->comment('Searches');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Searches', $stats['searches']['count'] ?? 0],
                ['Avg Duration', ($stats['searches']['avg_duration_ms'] ?? 0) . ' ms'],
                ['Avg Results', $stats['searches']['avg_results'] ?? 0],
                ['Avg Top Score', number_format($stats['searches']['avg_top_score'] ?? 0, 4)],
                ['Avg Embedding Time', ($stats['searches']['avg_embedding_time_ms'] ?? 0) . ' ms'],
                ['Avg Search Time', ($stats['searches']['avg_search_time_ms'] ?? 0) . ' ms'],
            ]
        );
        $this->newLine();

        // Batches section
        $this->comment('Batch Operations');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Batches', $stats['batches']['count'] ?? 0],
                ['Total Processed', $stats['batches']['total_processed'] ?? 0],
                ['Total Failed', $stats['batches']['total_failed'] ?? 0],
                ['Avg Throughput', ($stats['batches']['avg_throughput'] ?? 0) . '/sec'],
            ]
        );
        $this->newLine();

        // Failures section
        $this->comment('Failures');
        $failureCount = $stats['failures']['count'] ?? 0;
        $this->line("  Total Failures: {$failureCount}");

        if (!empty($stats['failures']['by_error'])) {
            $this->newLine();
            $errorRows = [];
            foreach ($stats['failures']['by_error'] as $error => $count) {
                $errorRows[] = [class_basename($error), $count];
            }
            $this->table(['Error Type', 'Count'], $errorRows);
        }

        return self::SUCCESS;
    }

    protected function showRecent(): int
    {
        $limit = (int) $this->option('limit');
        $type = $this->option('type');

        $collector = app(TelemetryCollector::class);
        $entries = $collector->getRecent($limit, $type);

        if ($this->option('json')) {
            $this->line($entries->toJson(JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info("Recent Telemetry Events" . ($type ? " (filtered: {$type})" : ''));
        $this->newLine();

        if ($entries->isEmpty()) {
            $this->warn('No telemetry entries found.');
            return self::SUCCESS;
        }

        $rows = $entries->map(function ($entry) {
            $duration = $entry->duration_ms;
            $durationStr = $duration !== null ? number_format($duration, 1) . ' ms' : '-';

            return [
                $entry->occurred_at->format('Y-m-d H:i:s'),
                $entry->event_name,
                $durationStr,
                $this->formatEventSummary($entry),
            ];
        })->toArray();

        $this->table(['Time', 'Event', 'Duration', 'Summary'], $rows);

        return self::SUCCESS;
    }

    protected function pruneEntries(): int
    {
        $days = config('brim.telemetry.store.retention_days', 30);

        if (!$this->confirm("This will delete telemetry entries older than {$days} days. Continue?")) {
            return self::SUCCESS;
        }

        $collector = app(TelemetryCollector::class);
        $deleted = $collector->prune();

        $this->info("Pruned {$deleted} telemetry entries.");

        return self::SUCCESS;
    }

    protected function invalidAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Available actions: stats, recent, prune');
        return self::FAILURE;
    }

    protected function formatEventSummary(TelemetryEntry $entry): string
    {
        $data = $entry->data;

        return match ($entry->category) {
            'embedding' => $this->formatEmbeddingSummary($entry),
            'search' => $this->formatSearchSummary($entry),
            'batch' => $this->formatBatchSummary($entry),
            default => '-',
        };
    }

    protected function formatEmbeddingSummary(TelemetryEntry $entry): string
    {
        $data = $entry->data;

        if ($entry->isFailure()) {
            return substr($data['error_message'] ?? 'Unknown error', 0, 40) . '...';
        }

        $modelType = class_basename($data['model_type'] ?? 'Unknown');
        $chunks = $data['chunk_count'] ?? '-';

        return "{$modelType}#{$data['model_id']} ({$chunks} chunks)";
    }

    protected function formatSearchSummary(TelemetryEntry $entry): string
    {
        $data = $entry->data;
        $modelClass = class_basename($data['model_class'] ?? 'Unknown');
        $results = $data['result_count'] ?? '-';
        $topScore = isset($data['top_score']) ? number_format($data['top_score'], 3) : '-';

        return "{$modelClass}: {$results} results (top: {$topScore})";
    }

    protected function formatBatchSummary(TelemetryEntry $entry): string
    {
        $data = $entry->data;
        $modelClass = class_basename($data['model_class'] ?? 'Unknown');
        $processed = $data['processed_count'] ?? $data['total_count'] ?? '-';
        $failed = $data['failed_count'] ?? 0;

        $result = "{$modelClass}: {$processed} processed";
        if ($failed > 0) {
            $result .= ", {$failed} failed";
        }

        return $result;
    }
}
