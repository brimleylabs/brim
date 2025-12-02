<?php

namespace Brim\Tests\Feature;

use Brim\Brim;
use Brim\Contracts\EmbeddingDriver;
use Brim\Contracts\VectorStore;
use Brim\Drivers\EmbeddingManager;
use Brim\Events\BrimBatchCompleted;
use Brim\Events\BrimBatchStarted;
use Brim\Events\BrimEmbeddingCompleted;
use Brim\Events\BrimEmbeddingFailed;
use Brim\Events\BrimEmbeddingStarted;
use Brim\Events\BrimSearchCompleted;
use Brim\Events\BrimSearchStarted;
use Brim\Exceptions\BrimException;
use Brim\Stores\VectorStoreManager;
use Brim\Support\TextChunker;
use Brim\Tests\Fixtures\TestArticle;
use Brim\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Mockery;

/**
 * @group feature
 * @group brim-service
 */
class BrimServiceTest extends TestCase
{
    private function createBrimWithMocks(
        ?EmbeddingDriver $driver = null,
        ?VectorStore $store = null
    ): Brim {
        $embeddingManager = Mockery::mock(EmbeddingManager::class);
        $storeManager = Mockery::mock(VectorStoreManager::class);

        if ($driver) {
            $embeddingManager->shouldReceive('driver')
                ->andReturn($driver);
        }

        if ($store) {
            $storeManager->shouldReceive('driver')
                ->andReturn($store);
        }

        return new Brim($embeddingManager, $storeManager, new TextChunker());
    }

    public function test_generate_for_creates_embedding(): void
    {
        Event::fake();

        $article = $this->createTestArticle([
            'title' => 'Test Article',
            'content' => 'This is test content.',
        ]);

        $embedding = $this->fakeEmbedding(self::NOMIC_DIMENSIONS);

        $mockDriver = Mockery::mock(EmbeddingDriver::class);
        $mockDriver->shouldReceive('modelName')->andReturn('nomic-embed-text');
        $mockDriver->shouldReceive('embedBatch')
            ->once()
            ->andReturn([$embedding]);
        $mockDriver->shouldReceive('dimensions')->andReturn(self::NOMIC_DIMENSIONS);

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('store')
            ->once()
            ->with(
                Mockery::type(TestArticle::class),
                Mockery::type('array'),
                'nomic-embed-text',
                Mockery::any()
            );

        $brim = $this->createBrimWithMocks($mockDriver, $mockStore);

        $brim->generateFor($article);

        Event::assertDispatched(BrimEmbeddingStarted::class);
        Event::assertDispatched(BrimEmbeddingCompleted::class);
    }

    public function test_generate_for_dispatches_failed_event_on_error(): void
    {
        Event::fake();

        $article = $this->createTestArticle();

        $mockDriver = Mockery::mock(EmbeddingDriver::class);
        $mockDriver->shouldReceive('modelName')->andReturn('nomic-embed-text');
        $mockDriver->shouldReceive('embedBatch')
            ->once()
            ->andThrow(new \RuntimeException('API error'));

        $brim = $this->createBrimWithMocks($mockDriver);

        try {
            $brim->generateFor($article);
        } catch (\Throwable $e) {
            // Expected
        }

        Event::assertDispatched(BrimEmbeddingStarted::class);
        Event::assertDispatched(BrimEmbeddingFailed::class);
    }

    public function test_delete_for_removes_embedding(): void
    {
        $article = $this->createTestArticle();

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('delete')
            ->once()
            ->with(Mockery::type(TestArticle::class));

        $brim = $this->createBrimWithMocks(null, $mockStore);

        $brim->deleteFor($article);

        // Mockery expectations verify the method was called
        $this->addToAssertionCount(1);
    }

    public function test_exists_for_checks_embedding_existence(): void
    {
        $article = $this->createTestArticle();

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('exists')
            ->once()
            ->with(Mockery::type(TestArticle::class))
            ->andReturn(true);

        $brim = $this->createBrimWithMocks(null, $mockStore);

        $this->assertTrue($brim->existsFor($article));
    }

    public function test_chunk_count_for_returns_count(): void
    {
        $article = $this->createTestArticle();

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('chunkCount')
            ->once()
            ->with(Mockery::type(TestArticle::class))
            ->andReturn(3);

        $brim = $this->createBrimWithMocks(null, $mockStore);

        $this->assertEquals(3, $brim->chunkCountFor($article));
    }

    public function test_search_returns_results(): void
    {
        Event::fake();

        $article1 = $this->createTestArticle(['title' => 'Article 1']);
        $article2 = $this->createTestArticle(['title' => 'Article 2']);

        $queryEmbedding = $this->fakeEmbedding(self::NOMIC_DIMENSIONS);

        $mockDriver = Mockery::mock(EmbeddingDriver::class);
        $mockDriver->shouldReceive('embed')
            ->once()
            ->andReturn($queryEmbedding);

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('search')
            ->once()
            ->andReturn(collect([
                ['model_id' => $article1->id, 'similarity' => 0.95],
                ['model_id' => $article2->id, 'similarity' => 0.85],
            ]));

        $brim = $this->createBrimWithMocks($mockDriver, $mockStore);

        $results = $brim->search(TestArticle::class, 'test query', 10, 0.0);

        $this->assertCount(2, $results);
        Event::assertDispatched(BrimSearchStarted::class);
        Event::assertDispatched(BrimSearchCompleted::class);
    }

    public function test_search_returns_empty_when_no_results(): void
    {
        Event::fake();

        $mockDriver = Mockery::mock(EmbeddingDriver::class);
        $mockDriver->shouldReceive('embed')
            ->once()
            ->andReturn($this->fakeEmbedding(self::NOMIC_DIMENSIONS));

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('search')
            ->once()
            ->andReturn(collect());

        $brim = $this->createBrimWithMocks($mockDriver, $mockStore);

        $results = $brim->search(TestArticle::class, 'nonexistent query', 10, 0.0);

        $this->assertCount(0, $results);
    }

    public function test_find_similar_returns_similar_models(): void
    {
        $article = $this->createTestArticle();
        $similarArticles = collect([
            $this->createTestArticle(['title' => 'Similar 1']),
            $this->createTestArticle(['title' => 'Similar 2']),
        ]);

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('findSimilar')
            ->once()
            ->with(Mockery::type(TestArticle::class), 5)
            ->andReturn($similarArticles);

        $brim = $this->createBrimWithMocks(null, $mockStore);

        $results = $brim->findSimilar($article, 5);

        $this->assertCount(2, $results);
    }

    public function test_batch_generate_processes_multiple_models(): void
    {
        Event::fake();

        $articles = collect([
            $this->createTestArticle(['title' => 'Article 1']),
            $this->createTestArticle(['title' => 'Article 2']),
            $this->createTestArticle(['title' => 'Article 3']),
        ]);

        $mockDriver = Mockery::mock(EmbeddingDriver::class);
        $mockDriver->shouldReceive('modelName')->andReturn('nomic-embed-text');
        $mockDriver->shouldReceive('embedBatch')
            ->times(3)
            ->andReturn([$this->fakeEmbedding(self::NOMIC_DIMENSIONS)]);
        $mockDriver->shouldReceive('dimensions')->andReturn(self::NOMIC_DIMENSIONS);

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('store')->times(3);

        $brim = $this->createBrimWithMocks($mockDriver, $mockStore);

        $result = $brim->batchGenerate(TestArticle::class, $articles);

        $this->assertEquals(3, $result['processed']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);

        Event::assertDispatched(BrimBatchStarted::class);
        Event::assertDispatched(BrimBatchCompleted::class);
    }

    public function test_batch_generate_tracks_failures(): void
    {
        Event::fake();

        $articles = collect([
            $this->createTestArticle(['title' => 'Article 1']),
            $this->createTestArticle(['title' => 'Article 2']),
        ]);

        $callCount = 0;
        $mockDriver = Mockery::mock(EmbeddingDriver::class);
        $mockDriver->shouldReceive('modelName')->andReturn('nomic-embed-text');
        $mockDriver->shouldReceive('embedBatch')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('API error');
                }
                return [$this->fakeEmbedding(self::NOMIC_DIMENSIONS)];
            });
        $mockDriver->shouldReceive('dimensions')->andReturn(self::NOMIC_DIMENSIONS);

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('store')->once();

        $brim = $this->createBrimWithMocks($mockDriver, $mockStore);

        $result = $brim->batchGenerate(TestArticle::class, $articles);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_generate_or_queue_generates_directly_when_queue_disabled(): void
    {
        $this->app['config']->set('brim.queue', false);

        $article = $this->createTestArticle();

        $mockDriver = Mockery::mock(EmbeddingDriver::class);
        $mockDriver->shouldReceive('modelName')->andReturn('nomic-embed-text');
        $mockDriver->shouldReceive('embedBatch')
            ->once()
            ->andReturn([$this->fakeEmbedding(self::NOMIC_DIMENSIONS)]);
        $mockDriver->shouldReceive('dimensions')->andReturn(self::NOMIC_DIMENSIONS);

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('store')->once();

        $brim = $this->createBrimWithMocks($mockDriver, $mockStore);

        $brim->generateOrQueueFor($article);

        // Mockery expectations verify the methods were called
        $this->addToAssertionCount(1);
    }

    public function test_stats_returns_store_statistics(): void
    {
        $expectedStats = [
            'total' => 100,
            'models' => 50,
            'by_type' => [],
        ];

        $mockStore = Mockery::mock(VectorStore::class);
        $mockStore->shouldReceive('stats')
            ->once()
            ->andReturn($expectedStats);

        $brim = $this->createBrimWithMocks(null, $mockStore);

        $stats = $brim->stats();

        $this->assertEquals($expectedStats, $stats);
    }

    public function test_health_check_returns_driver_health(): void
    {
        $expectedHealth = [
            'healthy' => true,
            'message' => 'Ollama running',
        ];

        $mockDriver = Mockery::mock(EmbeddingDriver::class);
        $mockDriver->shouldReceive('healthCheck')
            ->once()
            ->andReturn($expectedHealth);

        $brim = $this->createBrimWithMocks($mockDriver);

        $health = $brim->healthCheck();

        $this->assertEquals($expectedHealth, $health);
    }

    public function test_get_chunker_returns_chunker(): void
    {
        $brim = $this->createBrimWithMocks();

        $chunker = $brim->getChunker();

        $this->assertInstanceOf(TextChunker::class, $chunker);
    }

    public function test_validate_embeddable_throws_for_non_embeddable(): void
    {
        // Create a concrete model class that doesn't implement Embeddable
        $nonEmbeddableModel = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'test';
        };

        $brim = $this->createBrimWithMocks();

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($brim);
        $method = $reflection->getMethod('validateEmbeddable');
        $method->setAccessible(true);

        $this->expectException(BrimException::class);
        $this->expectExceptionMessage('does not implement the Embeddable interface');
        $method->invoke($brim, $nonEmbeddableModel);
    }
}
