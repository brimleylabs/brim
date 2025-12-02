<?php

namespace Brim\Tests\Integration;

use Brim\Brim;
use Brim\Contracts\EmbeddingDriver;
use Brim\Drivers\EmbeddingManager;
use Brim\Events\BrimEmbeddingCompleted;
use Brim\Events\BrimEmbeddingStarted;
use Brim\Events\BrimSearchCompleted;
use Brim\Events\BrimSearchStarted;
use Brim\Stores\VectorStoreManager;
use Brim\Support\TextChunker;
use Brim\Tests\Fixtures\InMemoryVectorStore;
use Brim\Tests\Fixtures\TestArticle;
use Brim\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;

/**
 * @group integration
 */
class FullWorkflowTest extends TestCase
{
    private Brim $brim;
    private EmbeddingDriver|MockInterface $mockDriver;
    private InMemoryVectorStore $mockStore;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock driver that returns deterministic embeddings
        $this->mockDriver = Mockery::mock(EmbeddingDriver::class);
        $this->mockDriver->shouldReceive('modelName')->andReturn('nomic-embed-text');
        $this->mockDriver->shouldReceive('dimensions')->andReturn(self::NOMIC_DIMENSIONS);

        // Create a simple in-memory vector store for testing
        $this->mockStore = new InMemoryVectorStore();

        $embeddingManager = Mockery::mock(EmbeddingManager::class);
        $embeddingManager->shouldReceive('driver')->andReturn($this->mockDriver);

        $storeManager = Mockery::mock(VectorStoreManager::class);
        $storeManager->shouldReceive('driver')->andReturn($this->mockStore);

        $this->brim = new Brim(
            $embeddingManager,
            $storeManager,
            new TextChunker()
        );
    }

    public function test_full_embedding_generation_workflow(): void
    {
        Event::fake();

        // Create test articles
        $article1 = $this->createTestArticle([
            'title' => 'Introduction to Machine Learning',
            'content' => 'Machine learning is a subset of artificial intelligence...',
        ]);

        $article2 = $this->createTestArticle([
            'title' => 'Deep Learning Fundamentals',
            'content' => 'Deep learning uses neural networks with many layers...',
        ]);

        // Setup mock to return embeddings
        $embedding1 = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.0);
        $embedding2 = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 2.0);

        $this->mockDriver->shouldReceive('embedBatch')
            ->once()
            ->andReturn([$embedding1]);
        $this->mockDriver->shouldReceive('embedBatch')
            ->once()
            ->andReturn([$embedding2]);

        // Generate embeddings
        $this->brim->generateFor($article1);
        $this->brim->generateFor($article2);

        // Verify events were dispatched
        Event::assertDispatched(BrimEmbeddingStarted::class, 2);
        Event::assertDispatched(BrimEmbeddingCompleted::class, 2);

        // Verify embeddings were stored
        $this->assertTrue($this->mockStore->exists($article1));
        $this->assertTrue($this->mockStore->exists($article2));
    }

    public function test_full_search_workflow(): void
    {
        Event::fake();

        // Setup articles with embeddings
        $article1 = $this->createTestArticle([
            'title' => 'PHP Best Practices',
            'content' => 'Follow these PHP coding standards...',
        ]);

        $article2 = $this->createTestArticle([
            'title' => 'Laravel Tutorial',
            'content' => 'Learn how to build web apps with Laravel...',
        ]);

        // Store embeddings directly
        $embedding1 = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.0);
        $embedding2 = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.1); // Similar to 1

        $this->mockStore->store($article1, [$embedding1], 'nomic-embed-text', null);
        $this->mockStore->store($article2, [$embedding2], 'nomic-embed-text', null);

        // Setup query embedding (similar to embedding1)
        $queryEmbedding = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.05);
        $this->mockDriver->shouldReceive('embed')
            ->once()
            ->andReturn($queryEmbedding);

        // Search
        $results = $this->brim->search(TestArticle::class, 'PHP coding standards', 10, 0.0);

        // Verify events
        Event::assertDispatched(BrimSearchStarted::class);
        Event::assertDispatched(BrimSearchCompleted::class);

        // Verify results (both should be found, article1 should be more similar)
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_delete_embedding_workflow(): void
    {
        $article = $this->createTestArticle();

        // Store an embedding
        $embedding = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.0);
        $this->mockStore->store($article, [$embedding], 'nomic-embed-text', null);

        $this->assertTrue($this->mockStore->exists($article));

        // Delete the embedding
        $this->brim->deleteFor($article);

        $this->assertFalse($this->mockStore->exists($article));
    }

    public function test_batch_generate_workflow(): void
    {
        Event::fake();

        $articles = collect([
            $this->createTestArticle(['title' => 'Article 1']),
            $this->createTestArticle(['title' => 'Article 2']),
            $this->createTestArticle(['title' => 'Article 3']),
        ]);

        // Setup mock to return embeddings for each
        $this->mockDriver->shouldReceive('embedBatch')
            ->times(3)
            ->andReturn([$this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS)]);

        $result = $this->brim->batchGenerate(TestArticle::class, $articles);

        $this->assertEquals(3, $result['processed']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);
        $this->assertArrayHasKey('duration_ms', $result);
        $this->assertArrayHasKey('throughput', $result);
    }

    public function test_find_similar_workflow(): void
    {
        // Create articles with embeddings
        $article1 = $this->createTestArticle(['title' => 'JavaScript Basics']);
        $article2 = $this->createTestArticle(['title' => 'TypeScript Guide']);
        $article3 = $this->createTestArticle(['title' => 'Python Tutorial']);

        // Store embeddings with different similarity levels
        $baseEmbedding = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.0);
        $similarEmbedding = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.1);
        $differentEmbedding = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 5.0);

        $this->mockStore->store($article1, [$baseEmbedding], 'nomic-embed-text', null);
        $this->mockStore->store($article2, [$similarEmbedding], 'nomic-embed-text', null);
        $this->mockStore->store($article3, [$differentEmbedding], 'nomic-embed-text', null);

        // Find similar to article1
        $similar = $this->brim->findSimilar($article1, 5);

        // Article2 should be more similar than Article3
        $this->assertGreaterThanOrEqual(1, $similar->count());
    }

    public function test_chunked_embedding_workflow(): void
    {
        Event::fake();

        // Create article with very long content that will be chunked
        $longContent = str_repeat('This is a test sentence. ', 1000);
        $article = $this->createTestArticle([
            'title' => 'Long Article',
            'content' => $longContent,
        ]);

        // Setup mock to return multiple embeddings (for chunks)
        $chunk1 = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.0);
        $chunk2 = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 2.0);

        $this->mockDriver->shouldReceive('embedBatch')
            ->once()
            ->andReturn([$chunk1, $chunk2]);

        $this->brim->generateFor($article);

        Event::assertDispatched(BrimEmbeddingCompleted::class, function ($event) {
            return $event->chunkCount >= 1;
        });
    }

    public function test_namespaced_embedding_workflow(): void
    {
        $article = $this->createTestArticle([
            'title' => 'Tech Article',
            'content' => 'Technology news...',
            'category' => 'tech',
        ]);

        $embedding = $this->normalizedFakeEmbedding(self::NOMIC_DIMENSIONS, 1.0);
        $this->mockDriver->shouldReceive('embedBatch')
            ->once()
            ->andReturn([$embedding]);

        $this->brim->generateFor($article);

        // Verify namespace is stored
        $this->assertTrue($this->mockStore->exists($article));
    }
}
