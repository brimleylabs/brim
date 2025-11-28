<?php

namespace Brim\Tests\Feature;

use Brim\Brim;
use Brim\Models\Embedding;
use Brim\Tests\Fixtures\TestArticle;
use Brim\Tests\TestCase;
use Mockery;

/**
 * @group feature
 * @group trait
 */
class HasEmbeddingsTraitTest extends TestCase
{
    public function test_model_implements_embeddable_methods(): void
    {
        $article = $this->createTestArticle([
            'title' => 'Test Title',
            'content' => 'Test Content',
        ]);

        $this->assertTrue(method_exists($article, 'toEmbeddableText'));
        $this->assertTrue(method_exists($article, 'getEmbeddingNamespace'));
        $this->assertTrue(method_exists($article, 'generateEmbedding'));
        $this->assertTrue(method_exists($article, 'hasEmbedding'));
        $this->assertTrue(method_exists($article, 'findSimilar'));
    }

    public function test_to_embeddable_text_returns_formatted_text(): void
    {
        $article = $this->createTestArticle([
            'title' => 'My Article',
            'content' => 'This is the content.',
        ]);

        $text = $article->toEmbeddableText();

        $this->assertStringContainsString('Title: My Article', $text);
        $this->assertStringContainsString('Content: This is the content.', $text);
    }

    public function test_get_embedding_namespace_returns_category(): void
    {
        $article = $this->createTestArticle([
            'title' => 'Tech Article',
            'content' => 'Content',
            'category' => 'technology',
        ]);

        $namespace = $article->getEmbeddingNamespace();

        $this->assertEquals('technology', $namespace);
    }

    public function test_get_embedding_namespace_returns_null_when_no_category(): void
    {
        $article = $this->createTestArticle([
            'title' => 'No Category Article',
            'content' => 'Content',
            'category' => null,
        ]);

        $namespace = $article->getEmbeddingNamespace();

        $this->assertNull($namespace);
    }

    public function test_embeddings_relationship(): void
    {
        $article = $this->createTestArticle();

        Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => $article->id,
            'chunk_index' => 0,
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5('test'),
            'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
        ]);

        $embeddings = $article->embeddings;

        $this->assertCount(1, $embeddings);
        $this->assertInstanceOf(Embedding::class, $embeddings->first());
    }

    public function test_has_embedding_returns_true_when_exists(): void
    {
        $article = $this->createTestArticle();

        // Mock the Brim service
        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('existsFor')
            ->with(Mockery::type(TestArticle::class))
            ->andReturn(true);

        $this->app->instance(Brim::class, $mockBrim);

        $this->assertTrue($article->hasEmbedding());
    }

    public function test_has_embedding_returns_false_when_not_exists(): void
    {
        $article = $this->createTestArticle();

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('existsFor')
            ->with(Mockery::type(TestArticle::class))
            ->andReturn(false);

        $this->app->instance(Brim::class, $mockBrim);

        $this->assertFalse($article->hasEmbedding());
    }

    public function test_embedding_chunk_count_returns_correct_count(): void
    {
        $article = $this->createTestArticle();

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('chunkCountFor')
            ->with(Mockery::type(TestArticle::class))
            ->andReturn(3);

        $this->app->instance(Brim::class, $mockBrim);

        $this->assertEquals(3, $article->embeddingChunkCount());
    }

    public function test_generate_embedding_calls_brim_service(): void
    {
        $article = $this->createTestArticle();

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('generateFor')
            ->once()
            ->with(Mockery::type(TestArticle::class));

        $this->app->instance(Brim::class, $mockBrim);

        $article->generateEmbedding();

        // Mockery expectations verify the method was called
        $this->addToAssertionCount(1);
    }

    public function test_delete_embedding_calls_brim_service(): void
    {
        $article = $this->createTestArticle();

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('deleteFor')
            ->once()
            ->with(Mockery::type(TestArticle::class));

        $this->app->instance(Brim::class, $mockBrim);

        $article->deleteEmbedding();

        // Mockery expectations verify the method was called
        $this->addToAssertionCount(1);
    }

    public function test_find_similar_calls_brim_service(): void
    {
        $article = $this->createTestArticle();

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('findSimilar')
            ->once()
            ->with(Mockery::type(TestArticle::class), 5)
            ->andReturn(collect());

        $this->app->instance(Brim::class, $mockBrim);

        $result = $article->findSimilar(5);

        $this->assertCount(0, $result);
    }

    public function test_auto_sync_disabled_does_not_generate_on_save(): void
    {
        // TestArticle has brimAutoSync = false
        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldNotReceive('generateOrQueueFor');

        $this->app->instance(Brim::class, $mockBrim);

        $article = TestArticle::create([
            'title' => 'Test',
            'content' => 'Content',
        ]);

        // Mockery shouldNotReceive verifies the method was not called
        $this->addToAssertionCount(1);
    }

    public function test_queue_or_generate_embedding_calls_brim(): void
    {
        $article = $this->createTestArticle();

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('generateOrQueueFor')
            ->once()
            ->with(Mockery::type(TestArticle::class));

        $this->app->instance(Brim::class, $mockBrim);

        $article->queueOrGenerateEmbedding();

        // Mockery expectations verify the method was called
        $this->addToAssertionCount(1);
    }

    public function test_deleted_model_removes_embedding(): void
    {
        $article = $this->createTestArticle();

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('deleteFor')
            ->once()
            ->with(Mockery::type(TestArticle::class));

        $this->app->instance(Brim::class, $mockBrim);

        $article->delete();

        // Mockery expectations verify the method was called
        $this->addToAssertionCount(1);
    }
}
