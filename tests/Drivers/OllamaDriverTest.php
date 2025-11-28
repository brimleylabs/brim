<?php

namespace Brim\Tests\Drivers;

use Brim\Drivers\OllamaDriver;
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
 * @group ollama
 */
class OllamaDriverTest extends TestCase
{
    private function createDriverWithMock(array $responses): OllamaDriver
    {
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OllamaDriver(
            [
                'host' => 'http://localhost:11434',
                'model' => 'nomic-embed-text',
                'timeout' => 30,
            ],
            new RetryHandler([
                'max_attempts' => 1,
                'base_delay_ms' => 1,
            ]),
            new TextChunker()
        );

        // Use reflection to inject mock client
        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        return $driver;
    }

    public function test_embed_returns_vector(): void
    {
        $expectedEmbedding = $this->fakeEmbedding(self::NOMIC_DIMENSIONS);
        $driver = $this->createDriverWithMock([
            $this->ollamaEmbeddingResponse($expectedEmbedding),
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

    public function test_embed_throws_on_whitespace_only(): void
    {
        $driver = $this->createDriverWithMock([]);

        $this->expectException(EmbeddingException::class);

        $driver->embed('   ');
    }

    public function test_embed_batch_returns_multiple_vectors(): void
    {
        $embedding1 = $this->fakeEmbedding(self::NOMIC_DIMENSIONS);
        $embedding2 = $this->fakeEmbedding(self::NOMIC_DIMENSIONS);

        $driver = $this->createDriverWithMock([
            $this->ollamaEmbeddingResponse($embedding1),
            $this->ollamaEmbeddingResponse($embedding2),
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

    public function test_throws_connection_exception_on_connection_failure(): void
    {
        $mockHandler = new MockHandler([
            new ConnectException(
                'Connection refused',
                new Request('POST', '/api/embed')
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OllamaDriver(
            [
                'host' => 'http://localhost:11434',
                'model' => 'nomic-embed-text',
            ],
            new RetryHandler(['max_attempts' => 1]),
            new TextChunker()
        );

        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Could not connect to Ollama');

        $driver->embed('test');
    }

    public function test_throws_exception_on_model_not_found(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Not Found',
                new Request('POST', '/api/embed'),
                new Response(404, [], 'model not found')
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OllamaDriver(
            [
                'host' => 'http://localhost:11434',
                'model' => 'nonexistent-model',
            ],
            new RetryHandler(['max_attempts' => 1]),
            new TextChunker()
        );

        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        // The retry handler wraps the exception
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('not available');

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

    public function test_throws_on_dimension_mismatch(): void
    {
        // nomic-embed-text expects 768 dimensions
        $wrongDimensionEmbedding = $this->fakeEmbedding(512);

        $driver = $this->createDriverWithMock([
            new Response(200, [], json_encode([
                'embeddings' => [$wrongDimensionEmbedding],
            ])),
        ]);

        // RetryHandler wraps the exception
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('dimension mismatch');

        $driver->embed('test');
    }

    public function test_dimensions_returns_correct_value(): void
    {
        $driver = new OllamaDriver([
            'model' => 'nomic-embed-text',
        ]);

        $this->assertEquals(768, $driver->dimensions());
    }

    public function test_dimensions_for_different_models(): void
    {
        $nomicDriver = new OllamaDriver(['model' => 'nomic-embed-text']);
        $allMiniDriver = new OllamaDriver(['model' => 'all-minilm']);
        $mxbaiDriver = new OllamaDriver(['model' => 'mxbai-embed-large']);

        $this->assertEquals(768, $nomicDriver->dimensions());
        $this->assertEquals(384, $allMiniDriver->dimensions());
        $this->assertEquals(1024, $mxbaiDriver->dimensions());
    }

    public function test_token_limit_returns_correct_value(): void
    {
        $nomicDriver = new OllamaDriver(['model' => 'nomic-embed-text']);
        $allMiniDriver = new OllamaDriver(['model' => 'all-minilm']);

        $this->assertEquals(8192, $nomicDriver->tokenLimit());
        $this->assertEquals(256, $allMiniDriver->tokenLimit());
    }

    public function test_model_name_returns_configured_model(): void
    {
        $driver = new OllamaDriver(['model' => 'nomic-embed-text']);

        $this->assertEquals('nomic-embed-text', $driver->modelName());
    }

    public function test_get_host_returns_configured_host(): void
    {
        $driver = new OllamaDriver([
            'host' => 'http://custom-host:11434',
        ]);

        $this->assertEquals('http://custom-host:11434', $driver->getHost());
    }

    public function test_host_trailing_slash_is_trimmed(): void
    {
        $driver = new OllamaDriver([
            'host' => 'http://localhost:11434/',
        ]);

        $this->assertEquals('http://localhost:11434', $driver->getHost());
    }

    public function test_health_check_returns_healthy_when_model_available(): void
    {
        $driver = $this->createDriverWithMock([
            $this->ollamaTagsResponse(['nomic-embed-text:latest']),
        ]);

        $health = $driver->healthCheck();

        $this->assertTrue($health['healthy']);
        $this->assertStringContainsString('available', $health['message']);
        $this->assertTrue($health['details']['model_installed']);
    }

    public function test_health_check_returns_healthy_but_warns_when_model_not_installed(): void
    {
        $driver = $this->createDriverWithMock([
            $this->ollamaTagsResponse(['other-model:latest']),
        ]);

        $health = $driver->healthCheck();

        $this->assertTrue($health['healthy']);
        $this->assertStringContainsString('not installed', $health['message']);
        $this->assertFalse($health['details']['model_installed']);
    }

    public function test_health_check_returns_unhealthy_when_connection_fails(): void
    {
        $mockHandler = new MockHandler([
            new ConnectException(
                'Connection refused',
                new Request('GET', '/api/tags')
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $driver = new OllamaDriver(
            ['host' => 'http://localhost:11434', 'model' => 'nomic-embed-text'],
            new RetryHandler(['max_attempts' => 1]),
            new TextChunker()
        );

        $reflection = new \ReflectionClass($driver);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        $health = $driver->healthCheck();

        $this->assertFalse($health['healthy']);
        $this->assertStringContainsString('Cannot connect', $health['message']);
    }

    public function test_uses_default_config_values(): void
    {
        $driver = new OllamaDriver([]);

        $this->assertEquals('http://localhost:11434', $driver->getHost());
        $this->assertEquals('nomic-embed-text', $driver->modelName());
    }
}
