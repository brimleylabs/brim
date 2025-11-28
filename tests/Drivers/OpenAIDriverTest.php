<?php

namespace Brim\Tests\Drivers;

use Brim\Drivers\OpenAIDriver;
use Brim\Exceptions\ConnectionException;
use Brim\Exceptions\EmbeddingException;
use Brim\Support\RetryHandler;
use Brim\Support\TextChunker;
use Brim\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * @group drivers
 * @group openai
 */
class OpenAIDriverTest extends TestCase
{
    private function createDriverWithMock(array $responses, ?string $apiKey = 'test-key'): OpenAIDriver
    {
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OpenAIDriver(
            [
                'api_key' => $apiKey,
                'model' => 'text-embedding-3-small',
                'timeout' => 30,
            ],
            new RetryHandler([
                'max_attempts' => 1,
                'base_delay_ms' => 1,
            ]),
            new TextChunker()
        );

        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        return $driver;
    }

    public function test_embed_returns_vector(): void
    {
        $expectedEmbedding = $this->fakeEmbedding(self::OPENAI_SMALL_DIMENSIONS);
        $driver = $this->createDriverWithMock([
            $this->openAiEmbeddingResponse([$expectedEmbedding]),
        ]);

        $result = $driver->embed('Test text');

        $this->assertEquals($expectedEmbedding, $result);
    }

    public function test_embed_throws_on_empty_input(): void
    {
        $driver = $this->createDriverWithMock([]);

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('Cannot generate embedding for empty input');

        $driver->embed('');
    }

    public function test_embed_throws_when_api_key_not_configured(): void
    {
        $driver = $this->createDriverWithMock([], '');

        // RetryHandler wraps the exception
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('API key is not configured');

        $driver->embed('test');
    }

    public function test_embed_batch_returns_multiple_vectors(): void
    {
        $embedding1 = $this->fakeEmbedding(self::OPENAI_SMALL_DIMENSIONS);
        $embedding2 = $this->fakeEmbedding(self::OPENAI_SMALL_DIMENSIONS);

        $driver = $this->createDriverWithMock([
            $this->openAiEmbeddingResponse([$embedding1, $embedding2]),
        ]);

        $results = $driver->embedBatch(['Text 1', 'Text 2']);

        $this->assertCount(2, $results);
        $this->assertEquals($embedding1, $results[0]);
        $this->assertEquals($embedding2, $results[1]);
    }

    public function test_embed_batch_returns_empty_for_empty_input(): void
    {
        $driver = $this->createDriverWithMock([]);

        $results = $driver->embedBatch([]);

        $this->assertEmpty($results);
    }

    public function test_batch_response_is_sorted_by_index(): void
    {
        $embedding0 = $this->fakeEmbedding(self::OPENAI_SMALL_DIMENSIONS);
        $embedding1 = $this->fakeEmbedding(self::OPENAI_SMALL_DIMENSIONS);

        // Response with items out of order
        $response = new Response(200, [], json_encode([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 1, 'embedding' => $embedding1],
                ['object' => 'embedding', 'index' => 0, 'embedding' => $embedding0],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]));

        $driver = $this->createDriverWithMock([$response]);

        $results = $driver->embedBatch(['Text 0', 'Text 1']);

        // Should be sorted by index
        $this->assertEquals($embedding0, $results[0]);
        $this->assertEquals($embedding1, $results[1]);
    }

    public function test_throws_connection_exception_on_timeout(): void
    {
        $mockHandler = new MockHandler([
            new ConnectException(
                'Connection timed out',
                new Request('POST', 'https://api.openai.com/v1/embeddings')
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OpenAIDriver(
            ['api_key' => 'test-key', 'model' => 'text-embedding-3-small', 'timeout' => 30],
            new RetryHandler(['max_attempts' => 1]),
            new TextChunker()
        );

        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('timed out');

        $driver->embed('test');
    }

    public function test_throws_on_rate_limit(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Rate limited',
                new Request('POST', 'https://api.openai.com/v1/embeddings'),
                new Response(429, ['Retry-After' => '60'], 'Rate limit exceeded')
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OpenAIDriver(
            ['api_key' => 'test-key', 'model' => 'text-embedding-3-small'],
            new RetryHandler(['max_attempts' => 1]),
            new TextChunker()
        );

        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Rate limited');

        $driver->embed('test');
    }

    public function test_throws_exception_on_invalid_api_key(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Unauthorized',
                new Request('POST', 'https://api.openai.com/v1/embeddings'),
                new Response(401, [], 'Invalid API key')
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OpenAIDriver(
            ['api_key' => 'invalid-key', 'model' => 'text-embedding-3-small'],
            new RetryHandler(['max_attempts' => 1]),
            new TextChunker()
        );

        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        // RetryHandler wraps the exception
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Invalid OpenAI API key');

        $driver->embed('test');
    }

    public function test_throws_on_invalid_response_format(): void
    {
        $driver = $this->createDriverWithMock([
            new Response(200, [], json_encode(['invalid' => 'response'])),
        ]);

        // RetryHandler wraps the exception
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Invalid response');

        $driver->embed('test');
    }

    public function test_throws_on_missing_embedding_in_response_item(): void
    {
        $response = new Response(200, [], json_encode([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0], // Missing embedding field
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]));

        $driver = $this->createDriverWithMock([$response]);

        // RetryHandler wraps the exception
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Invalid response');

        $driver->embed('test');
    }

    public function test_throws_on_dimension_mismatch(): void
    {
        $wrongDimensionEmbedding = $this->fakeEmbedding(768);

        $response = new Response(200, [], json_encode([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => $wrongDimensionEmbedding],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]));

        $driver = $this->createDriverWithMock([$response]);

        // RetryHandler wraps the exception
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('dimension mismatch');

        $driver->embed('test');
    }

    public function test_dimensions_returns_correct_value(): void
    {
        $smallDriver = new OpenAIDriver(['api_key' => 'key', 'model' => 'text-embedding-3-small']);
        $largeDriver = new OpenAIDriver(['api_key' => 'key', 'model' => 'text-embedding-3-large']);
        $adaDriver = new OpenAIDriver(['api_key' => 'key', 'model' => 'text-embedding-ada-002']);

        $this->assertEquals(1536, $smallDriver->dimensions());
        $this->assertEquals(3072, $largeDriver->dimensions());
        $this->assertEquals(1536, $adaDriver->dimensions());
    }

    public function test_token_limit_returns_correct_value(): void
    {
        $driver = new OpenAIDriver(['api_key' => 'key', 'model' => 'text-embedding-3-small']);

        $this->assertEquals(8191, $driver->tokenLimit());
    }

    public function test_model_name_returns_configured_model(): void
    {
        $driver = new OpenAIDriver([
            'api_key' => 'key',
            'model' => 'text-embedding-3-large',
        ]);

        $this->assertEquals('text-embedding-3-large', $driver->modelName());
    }

    public function test_health_check_returns_healthy_when_api_works(): void
    {
        $driver = $this->createDriverWithMock([
            $this->openAiEmbeddingResponse([$this->fakeEmbedding(self::OPENAI_SMALL_DIMENSIONS)]),
        ]);

        $health = $driver->healthCheck();

        $this->assertTrue($health['healthy']);
        $this->assertStringContainsString('connected', $health['message']);
        $this->assertTrue($health['details']['api_key_set']);
    }

    public function test_health_check_returns_unhealthy_when_no_api_key(): void
    {
        $driver = $this->createDriverWithMock([], '');

        $health = $driver->healthCheck();

        $this->assertFalse($health['healthy']);
        $this->assertStringContainsString('not configured', $health['message']);
        $this->assertFalse($health['details']['api_key_set']);
    }

    public function test_health_check_returns_unhealthy_on_api_error(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Unauthorized',
                new Request('POST', 'https://api.openai.com/v1/embeddings'),
                new Response(401, [], 'Invalid API key')
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OpenAIDriver(
            ['api_key' => 'invalid-key', 'model' => 'text-embedding-3-small'],
            new RetryHandler(['max_attempts' => 1]),
            new TextChunker()
        );

        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        $health = $driver->healthCheck();

        $this->assertFalse($health['healthy']);
        $this->assertTrue($health['details']['api_key_set']);
    }

    public function test_uses_default_config_values(): void
    {
        $driver = new OpenAIDriver([]);

        $this->assertEquals('text-embedding-3-small', $driver->modelName());
    }
}
