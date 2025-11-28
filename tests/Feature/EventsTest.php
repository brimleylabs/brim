<?php

namespace Brim\Tests\Feature;

use Brim\Events\BrimBatchCompleted;
use Brim\Events\BrimBatchStarted;
use Brim\Events\BrimEmbeddingCompleted;
use Brim\Events\BrimEmbeddingFailed;
use Brim\Events\BrimEmbeddingStarted;
use Brim\Events\BrimSearchCompleted;
use Brim\Events\BrimSearchStarted;
use Brim\Tests\Fixtures\TestArticle;
use Brim\Tests\TestCase;

/**
 * @group feature
 * @group events
 */
class EventsTest extends TestCase
{
    public function test_embedding_started_event_properties(): void
    {
        $article = $this->createTestArticle();

        $event = new BrimEmbeddingStarted($article, 'ollama', 500);

        $this->assertSame($article, $event->model);
        $this->assertEquals('ollama', $event->driver);
        $this->assertEquals(500, $event->textLength);
        $this->assertEquals(TestArticle::class, $event->metadata['model_type']);
        $this->assertEquals($article->id, $event->metadata['model_id']);
    }

    public function test_embedding_completed_event_properties(): void
    {
        $article = $this->createTestArticle();
        $startTime = microtime(true);
        usleep(10000); // 10ms delay
        $embeddingEndTime = microtime(true);

        $event = new BrimEmbeddingCompleted(
            $article,
            'ollama',
            2,
            self::NOMIC_DIMENSIONS,
            $startTime,
            $embeddingEndTime
        );

        $this->assertSame($article, $event->model);
        $this->assertEquals('ollama', $event->driver);
        $this->assertEquals(2, $event->chunkCount);
        $this->assertEquals(self::NOMIC_DIMENSIONS, $event->dimensions);
        $this->assertGreaterThan(0, $event->embeddingTime);
        $this->assertGreaterThan(0, $event->duration);
    }

    public function test_embedding_failed_event_properties(): void
    {
        $article = $this->createTestArticle();
        $exception = new \RuntimeException('Test error');
        $startTime = microtime(true);

        $event = new BrimEmbeddingFailed($article, 'ollama', $exception, 2, $startTime);

        $this->assertSame($article, $event->model);
        $this->assertEquals('ollama', $event->driver);
        $this->assertSame($exception, $event->exception);
        $this->assertEquals(2, $event->retryAttempt);
        $this->assertEquals('RuntimeException', $event->metadata['error_class']);
        $this->assertEquals('Test error', $event->metadata['error_message']);
    }

    public function test_search_started_event_properties(): void
    {
        $event = new BrimSearchStarted(
            TestArticle::class,
            'test query',
            10,
            0.5,
            'tech'
        );

        $this->assertEquals(TestArticle::class, $event->modelClass);
        $this->assertEquals('test query', $event->query);
        $this->assertEquals(10, $event->limit);
        $this->assertEquals(0.5, $event->minSimilarity);
        $this->assertEquals('tech', $event->namespace);
        $this->assertEquals(10, $event->metadata['query_length']);
    }

    public function test_search_completed_event_properties(): void
    {
        $article1 = $this->createTestArticle();
        $article2 = $this->createTestArticle();
        $article1->similarity_score = 0.95;
        $article2->similarity_score = 0.85;

        $results = collect([$article1, $article2]);

        $startTime = microtime(true);
        usleep(10000); // 10ms
        $embeddingEndTime = microtime(true);
        usleep(5000); // 5ms
        $searchEndTime = microtime(true);

        $event = new BrimSearchCompleted(
            TestArticle::class,
            'test query',
            $results,
            $startTime,
            $embeddingEndTime,
            $searchEndTime
        );

        $this->assertEquals(TestArticle::class, $event->modelClass);
        $this->assertEquals('test query', $event->query);
        $this->assertEquals(2, $event->resultCount);
        $this->assertEquals(0.95, $event->topScore);
        $this->assertGreaterThan(0, $event->embeddingTime);
        $this->assertGreaterThan(0, $event->searchTime);
        $this->assertGreaterThan(0, $event->totalDuration);
    }

    public function test_search_completed_handles_empty_results(): void
    {
        $startTime = microtime(true);

        $event = new BrimSearchCompleted(
            TestArticle::class,
            'test query',
            collect(),
            $startTime,
            $startTime,
            $startTime
        );

        $this->assertEquals(0, $event->resultCount);
        $this->assertNull($event->topScore);
    }

    public function test_batch_started_event_properties(): void
    {
        $event = new BrimBatchStarted(TestArticle::class, 100, 'embed');

        $this->assertEquals(TestArticle::class, $event->modelClass);
        $this->assertEquals(100, $event->totalCount);
        $this->assertEquals('embed', $event->operation);
    }

    public function test_batch_completed_event_properties(): void
    {
        $startTime = microtime(true);
        usleep(10000); // 10ms delay

        $event = new BrimBatchCompleted(
            TestArticle::class,
            95,
            5,
            'embed',
            $startTime
        );

        $this->assertEquals(TestArticle::class, $event->modelClass);
        $this->assertEquals(95, $event->processedCount);
        $this->assertEquals(5, $event->failedCount);
        $this->assertEquals('embed', $event->operation);
        $this->assertGreaterThan(0, $event->duration);
        $this->assertIsFloat($event->throughput);
    }

    public function test_batch_completed_calculates_throughput(): void
    {
        $startTime = microtime(true) - 1; // 1 second ago

        $event = new BrimBatchCompleted(
            TestArticle::class,
            10,
            0,
            'embed',
            $startTime
        );

        // Should be approximately 10 per second
        $this->assertGreaterThan(5, $event->throughput);
        $this->assertLessThan(15, $event->throughput);
    }
}
