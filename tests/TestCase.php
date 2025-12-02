<?php

namespace Brim\Tests;

use Brim\BrimServiceProvider;
use Brim\Brim;
use Brim\Drivers\EmbeddingManager;
use Brim\Stores\VectorStoreManager;
use Brim\Support\TextChunker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Brim\Tests\Fixtures\TestArticle;
use Mockery;

abstract class TestCase extends Orchestra
{
    /** @var int Dimensions for Ollama nomic-embed-text model */
    protected const NOMIC_DIMENSIONS = 768;

    /** @var int Dimensions for Ollama all-minilm model */
    protected const MINILM_DIMENSIONS = 384;

    /** @var int Dimensions for Ollama mxbai-embed-large model */
    protected const MXBAI_DIMENSIONS = 1024;

    /** @var int Dimensions for OpenAI text-embedding-3-small model */
    protected const OPENAI_SMALL_DIMENSIONS = 1536;

    /** @var int Dimensions for OpenAI text-embedding-3-large model */
    protected const OPENAI_LARGE_DIMENSIONS = 3072;

    protected MockHandler $mockHandler;
    protected Client $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            BrimServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use SQLite in-memory for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Default Brim config for testing
        $app['config']->set('brim.embeddings.driver', 'ollama');
        $app['config']->set('brim.embeddings.ollama.host', 'http://localhost:11434');
        $app['config']->set('brim.embeddings.ollama.model', 'nomic-embed-text');
        $app['config']->set('brim.embeddings.openai.api_key', 'test-api-key');
        $app['config']->set('brim.embeddings.openai.model', 'text-embedding-3-small');
        $app['config']->set('brim.auto_sync', false); // Disable auto-sync for tests
        $app['config']->set('brim.queue', false);
        $app['config']->set('brim.chunking.enabled', true);
        $app['config']->set('brim.chunking.overlap_words', 50);
        $app['config']->set('brim.retry.max_attempts', 3);
        $app['config']->set('brim.retry.base_delay_ms', 10); // Fast retries for tests
        $app['config']->set('brim.retry.max_delay_ms', 50);
        $app['config']->set('brim.search.limit', 10);
        $app['config']->set('brim.search.min_similarity', 0.0);
        $app['config']->set('brim.telemetry.enabled', false);
    }

    protected function setUpDatabase(): void
    {
        // Create test articles table
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('category')->nullable();
            $table->timestamps();
        });

        // Create brim_embeddings table (simplified for SQLite testing)
        Schema::create('brim_embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->integer('chunk_index')->default(0);
            $table->string('namespace')->nullable();
            $table->string('embedding_model');
            $table->string('content_hash', 64);
            $table->text('embedding'); // Store as JSON text in SQLite
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('namespace');
        });

        // Create telemetry table (matches TelemetryEntry model expectations)
        Schema::create('brim_telemetry', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->json('data')->nullable();
            $table->timestamp('occurred_at')->nullable();

            $table->index('event');
            $table->index('occurred_at');
        });
    }

    /**
     * Create a mock Guzzle client with predefined responses.
     */
    protected function createMockClient(array $responses = []): Client
    {
        $this->mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($this->mockHandler);
        $this->mockClient = new Client(['handler' => $handlerStack]);

        return $this->mockClient;
    }

    /**
     * Create a successful Ollama embedding response.
     */
    protected function ollamaEmbeddingResponse(array $embedding = null, int $status = 200): Response
    {
        $embedding = $embedding ?? $this->fakeEmbedding(768);

        return new Response($status, [], json_encode([
            'embeddings' => [$embedding],
        ]));
    }

    /**
     * Create a successful OpenAI embedding response.
     */
    protected function openAiEmbeddingResponse(array $embeddings = null, int $status = 200): Response
    {
        if ($embeddings === null) {
            $embeddings = [$this->fakeEmbedding(1536)];
        }

        $data = array_map(function ($embedding, $index) {
            return [
                'object' => 'embedding',
                'index' => $index,
                'embedding' => $embedding,
            ];
        }, $embeddings, array_keys($embeddings));

        return new Response($status, [], json_encode([
            'object' => 'list',
            'data' => $data,
            'model' => 'text-embedding-3-small',
            'usage' => [
                'prompt_tokens' => 10,
                'total_tokens' => 10,
            ],
        ]));
    }

    /**
     * Create an Ollama tags response (for health check).
     */
    protected function ollamaTagsResponse(array $models = ['nomic-embed-text:latest']): Response
    {
        return new Response(200, [], json_encode([
            'models' => array_map(fn($m) => ['name' => $m], $models),
        ]));
    }

    /**
     * Create a fake embedding vector.
     */
    protected function fakeEmbedding(int $dimensions = 768): array
    {
        $embedding = [];
        for ($i = 0; $i < $dimensions; $i++) {
            $embedding[] = (mt_rand(-1000, 1000) / 1000);
        }
        return $embedding;
    }

    /**
     * Create a normalized fake embedding for similarity testing.
     */
    protected function normalizedFakeEmbedding(int $dimensions = 768, float $seed = 0.0): array
    {
        $embedding = [];
        $sum = 0;

        for ($i = 0; $i < $dimensions; $i++) {
            $value = sin($i + $seed);
            $embedding[] = $value;
            $sum += $value * $value;
        }

        // Normalize
        $magnitude = sqrt($sum);
        if ($magnitude > 0) {
            $embedding = array_map(fn($v) => $v / $magnitude, $embedding);
        }

        return $embedding;
    }

    /**
     * Create a test article model.
     */
    protected function createTestArticle(array $attributes = []): TestArticle
    {
        return TestArticle::create(array_merge([
            'title' => 'Test Article',
            'content' => 'This is test content for embedding generation.',
            'category' => 'testing',
        ], $attributes));
    }

    /**
     * Assert that an embedding was stored for a model.
     */
    protected function assertEmbeddingExists($model, int $expectedChunks = 1): void
    {
        $this->assertDatabaseHas('brim_embeddings', [
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
        ]);

        $this->assertEquals(
            $expectedChunks,
            \Brim\Models\Embedding::where('model_type', get_class($model))
                ->where('model_id', $model->getKey())
                ->count()
        );
    }

    /**
     * Assert no embedding exists for a model.
     */
    protected function assertEmbeddingNotExists($model): void
    {
        $this->assertDatabaseMissing('brim_embeddings', [
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
        ]);
    }

    /**
     * Insert a test embedding directly into the database.
     */
    protected function insertTestEmbedding($model, array $embedding = null, string $namespace = null): void
    {
        $embedding = $embedding ?? $this->fakeEmbedding(768);

        \Brim\Models\Embedding::create([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'chunk_index' => 0,
            'namespace' => $namespace,
            'embedding_model' => 'nomic-embed-text',
            'content_hash' => md5($model->toEmbeddableText()),
            'embedding' => json_encode($embedding),
        ]);
    }
}
