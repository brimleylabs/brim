<?php

namespace Brim\Tests\Unit;

use Brim\Models\TelemetryEntry;
use Brim\Tests\TestCase;

/**
 * @group unit
 * @group telemetry
 */
class TelemetryEntryTest extends TestCase
{
    public function test_creates_telemetry_entry(): void
    {
        $entry = TelemetryEntry::create([
            'event' => 'embedding.completed',
            'data' => [
                'model_type' => 'App\\Models\\Article',
                'model_id' => 1,
                'duration_ms' => 245.5,
            ],
            'occurred_at' => now(),
        ]);

        $this->assertDatabaseHas('brim_telemetry', [
            'event' => 'embedding.completed',
        ]);
    }

    public function test_casts_data_as_array(): void
    {
        $entry = TelemetryEntry::create([
            'event' => 'search.completed',
            'data' => ['results' => 5, 'duration' => 100],
            'occurred_at' => now(),
        ]);

        $fresh = $entry->fresh();

        $this->assertIsArray($fresh->data);
        $this->assertEquals(5, $fresh->data['results']);
    }

    public function test_casts_occurred_at_as_datetime(): void
    {
        $entry = TelemetryEntry::create([
            'event' => 'embedding.started',
            'data' => [],
            'occurred_at' => '2025-01-15 10:30:00',
        ]);

        $fresh = $entry->fresh();

        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->occurred_at);
    }

    public function test_scope_of_type(): void
    {
        TelemetryEntry::create([
            'event' => 'embedding.started',
            'data' => [],
            'occurred_at' => now(),
        ]);

        TelemetryEntry::create([
            'event' => 'embedding.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        TelemetryEntry::create([
            'event' => 'search.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        $embeddingEvents = TelemetryEntry::ofType('embedding.')->get();

        $this->assertCount(2, $embeddingEvents);
    }

    public function test_scope_since(): void
    {
        TelemetryEntry::create([
            'event' => 'embedding.completed',
            'data' => [],
            'occurred_at' => now()->subDays(2),
        ]);

        TelemetryEntry::create([
            'event' => 'embedding.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        $recentEvents = TelemetryEntry::since(now()->subDay())->get();

        $this->assertCount(1, $recentEvents);
    }

    public function test_scope_embeddings(): void
    {
        TelemetryEntry::create([
            'event' => 'embedding.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        TelemetryEntry::create([
            'event' => 'search.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        $embeddings = TelemetryEntry::embeddings()->get();

        $this->assertCount(1, $embeddings);
    }

    public function test_scope_searches(): void
    {
        TelemetryEntry::create([
            'event' => 'search.started',
            'data' => [],
            'occurred_at' => now(),
        ]);

        TelemetryEntry::create([
            'event' => 'search.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        TelemetryEntry::create([
            'event' => 'embedding.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        $searches = TelemetryEntry::searches()->get();

        $this->assertCount(2, $searches);
    }

    public function test_scope_batches(): void
    {
        TelemetryEntry::create([
            'event' => 'batch.started',
            'data' => [],
            'occurred_at' => now(),
        ]);

        TelemetryEntry::create([
            'event' => 'batch.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        $batches = TelemetryEntry::batches()->get();

        $this->assertCount(2, $batches);
    }

    public function test_scope_failures(): void
    {
        TelemetryEntry::create([
            'event' => 'embedding.failed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        TelemetryEntry::create([
            'event' => 'embedding.completed',
            'data' => [],
            'occurred_at' => now(),
        ]);

        $failures = TelemetryEntry::failures()->get();

        $this->assertCount(1, $failures);
    }

    public function test_get_data_value(): void
    {
        $entry = TelemetryEntry::create([
            'event' => 'embedding.completed',
            'data' => ['duration_ms' => 150.5, 'chunks' => 3],
            'occurred_at' => now(),
        ]);

        $this->assertEquals(150.5, $entry->getDataValue('duration_ms'));
        $this->assertEquals(3, $entry->getDataValue('chunks'));
        $this->assertNull($entry->getDataValue('nonexistent'));
        $this->assertEquals('default', $entry->getDataValue('nonexistent', 'default'));
    }

    public function test_event_name_attribute(): void
    {
        $events = [
            'embedding.started' => 'Embedding Started',
            'embedding.completed' => 'Embedding Completed',
            'embedding.failed' => 'Embedding Failed',
            'search.started' => 'Search Started',
            'search.completed' => 'Search Completed',
            'batch.started' => 'Batch Started',
            'batch.completed' => 'Batch Completed',
            'custom.event' => 'Custom Event',
        ];

        foreach ($events as $event => $expectedName) {
            $entry = new TelemetryEntry(['event' => $event]);
            $this->assertEquals($expectedName, $entry->event_name);
        }
    }

    public function test_category_attribute(): void
    {
        $entry1 = new TelemetryEntry(['event' => 'embedding.completed']);
        $entry2 = new TelemetryEntry(['event' => 'search.started']);
        $entry3 = new TelemetryEntry(['event' => 'batch.completed']);

        $this->assertEquals('embedding', $entry1->category);
        $this->assertEquals('search', $entry2->category);
        $this->assertEquals('batch', $entry3->category);
    }

    public function test_is_failure(): void
    {
        $success = new TelemetryEntry(['event' => 'embedding.completed']);
        $failure = new TelemetryEntry(['event' => 'embedding.failed']);

        $this->assertFalse($success->isFailure());
        $this->assertTrue($failure->isFailure());
    }

    public function test_duration_ms_attribute(): void
    {
        $entry1 = new TelemetryEntry([
            'event' => 'embedding.completed',
            'data' => ['duration_ms' => 100.5],
        ]);

        $entry2 = new TelemetryEntry([
            'event' => 'search.completed',
            'data' => ['total_duration_ms' => 50.2],
        ]);

        $entry3 = new TelemetryEntry([
            'event' => 'embedding.started',
            'data' => [],
        ]);

        $this->assertEquals(100.5, $entry1->duration_ms);
        $this->assertEquals(50.2, $entry2->duration_ms);
        $this->assertNull($entry3->duration_ms);
    }

    public function test_model_identifier_attribute(): void
    {
        $entry1 = new TelemetryEntry([
            'event' => 'embedding.completed',
            'data' => [
                'model_type' => 'App\\Models\\Article',
                'model_id' => 42,
            ],
        ]);

        $entry2 = new TelemetryEntry([
            'event' => 'search.completed',
            'data' => [
                'model_class' => 'App\\Models\\Product',
            ],
        ]);

        $entry3 = new TelemetryEntry([
            'event' => 'batch.started',
            'data' => [],
        ]);

        $this->assertEquals('Article#42', $entry1->model_identifier);
        $this->assertEquals('Product', $entry2->model_identifier);
        $this->assertNull($entry3->model_identifier);
    }
}
