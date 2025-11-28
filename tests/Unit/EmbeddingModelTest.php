<?php

namespace Brim\Tests\Unit;

use Brim\Models\Embedding;
use Brim\Tests\Fixtures\TestArticle;
use Brim\Tests\TestCase;

/**
 * @group unit
 * @group models
 */
class EmbeddingModelTest extends TestCase
{
    public function test_creates_embedding_with_required_attributes(): void
    {
        $article = $this->createTestArticle();

        $embedding = Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => $article->id,
            'chunk_index' => 0,
            'namespace' => null,
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5('test content'),
            'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
        ]);

        $this->assertDatabaseHas('brim_embeddings', [
            'model_type' => TestArticle::class,
            'model_id' => $article->id,
        ]);
    }

    public function test_scope_for_type(): void
    {
        $article = $this->createTestArticle();

        $embedding = Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => $article->id,
            'chunk_index' => 0,
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5('test'),
            'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
        ]);

        $results = Embedding::forType(TestArticle::class)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($embedding->id, $results->first()->id);
    }

    public function test_scope_in_namespace(): void
    {
        $article = $this->createTestArticle();

        Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => $article->id,
            'chunk_index' => 0,
            'namespace' => 'tech',
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5('test'),
            'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
        ]);

        Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => $article->id + 100,
            'chunk_index' => 0,
            'namespace' => 'sports',
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5('test2'),
            'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
        ]);

        $techResults = Embedding::inNamespace('tech')->get();
        $sportsResults = Embedding::inNamespace('sports')->get();
        $nullResults = Embedding::inNamespace(null)->get();

        $this->assertCount(1, $techResults);
        $this->assertCount(1, $sportsResults);
        $this->assertCount(0, $nullResults);
    }

    public function test_scope_using_model(): void
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

        Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => $article->id + 100,
            'chunk_index' => 0,
            'embedding_model' => 'text-embedding-3-small',
            'content_hash' => md5('test2'),
            'embedding' => json_encode($this->fakeEmbedding(self::OPENAI_SMALL_DIMENSIONS)),
        ]);

        $nomicResults = Embedding::usingModel('nomic-embed-text')->get();
        $openaiResults = Embedding::usingModel('text-embedding-3-small')->get();

        $this->assertCount(1, $nomicResults);
        $this->assertCount(1, $openaiResults);
    }

    public function test_morph_to_relationship(): void
    {
        $article = $this->createTestArticle([
            'title' => 'Test Morph Article',
        ]);

        Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => $article->id,
            'chunk_index' => 0,
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5('test'),
            'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
        ]);

        $embedding = Embedding::first();
        $relatedModel = $embedding->embeddable;

        $this->assertInstanceOf(TestArticle::class, $relatedModel);
        $this->assertEquals('Test Morph Article', $relatedModel->title);
    }

    public function test_multiple_chunks_for_same_model(): void
    {
        $article = $this->createTestArticle();

        for ($i = 0; $i < 3; $i++) {
            Embedding::create([
                'model_type' => TestArticle::class,
                'model_id' => $article->id,
                'chunk_index' => $i,
                'embedding_model' => 'nomic-embed-text',
                'content_hash' => md5("test-chunk-{$i}"),
                'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
            ]);
        }

        $embeddings = Embedding::where('model_type', TestArticle::class)
            ->where('model_id', $article->id)
            ->orderBy('chunk_index')
            ->get();

        $this->assertCount(3, $embeddings);
        $this->assertEquals(0, $embeddings[0]->chunk_index);
        $this->assertEquals(1, $embeddings[1]->chunk_index);
        $this->assertEquals(2, $embeddings[2]->chunk_index);
    }

    public function test_casts_model_id_to_integer(): void
    {
        $article = $this->createTestArticle();

        $embedding = Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => (string) $article->id,
            'chunk_index' => 0,
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5('test'),
            'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
        ]);

        $this->assertIsInt($embedding->fresh()->model_id);
    }

    public function test_casts_chunk_index_to_integer(): void
    {
        $article = $this->createTestArticle();

        $embedding = Embedding::create([
            'model_type' => TestArticle::class,
            'model_id' => $article->id,
            'chunk_index' => '5',
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5('test'),
            'embedding' => json_encode($this->fakeEmbedding(self::NOMIC_DIMENSIONS)),
        ]);

        $this->assertIsInt($embedding->fresh()->chunk_index);
    }
}
