<?php

namespace Brim\Tests\Drivers;

use Brim\Contracts\EmbeddingDriver;
use Brim\Drivers\EmbeddingManager;
use Brim\Drivers\OllamaDriver;
use Brim\Drivers\OpenAIDriver;
use Brim\Exceptions\BrimException;
use Brim\Tests\TestCase;

/**
 * @group drivers
 * @group manager
 */
class EmbeddingManagerTest extends TestCase
{
    private EmbeddingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->app->make(EmbeddingManager::class);
    }

    public function test_get_default_driver_returns_configured_driver(): void
    {
        $this->app['config']->set('brim.embeddings.driver', 'ollama');

        $defaultDriver = $this->manager->getDefaultDriver();

        $this->assertEquals('ollama', $defaultDriver);
    }

    public function test_driver_returns_ollama_driver(): void
    {
        $driver = $this->manager->driver('ollama');

        $this->assertInstanceOf(EmbeddingDriver::class, $driver);
        $this->assertInstanceOf(OllamaDriver::class, $driver);
    }

    public function test_driver_returns_openai_driver(): void
    {
        $driver = $this->manager->driver('openai');

        $this->assertInstanceOf(EmbeddingDriver::class, $driver);
        $this->assertInstanceOf(OpenAIDriver::class, $driver);
    }

    public function test_driver_returns_default_when_null(): void
    {
        $this->app['config']->set('brim.embeddings.driver', 'ollama');

        $driver = $this->manager->driver();

        $this->assertInstanceOf(OllamaDriver::class, $driver);
    }

    public function test_driver_caches_instances(): void
    {
        $driver1 = $this->manager->driver('ollama');
        $driver2 = $this->manager->driver('ollama');

        $this->assertSame($driver1, $driver2);
    }

    public function test_different_drivers_are_different_instances(): void
    {
        $ollamaDriver = $this->manager->driver('ollama');
        $openaiDriver = $this->manager->driver('openai');

        $this->assertNotSame($ollamaDriver, $openaiDriver);
    }

    public function test_throws_exception_for_unsupported_driver(): void
    {
        $this->expectException(BrimException::class);
        $this->expectExceptionMessage('is not configured');

        $this->manager->driver('unsupported');
    }

    public function test_available_drivers_returns_all_drivers(): void
    {
        $drivers = $this->manager->availableDrivers();

        $this->assertIsArray($drivers);
        $this->assertContains('ollama', $drivers);
        $this->assertContains('openai', $drivers);
    }

    public function test_ollama_driver_uses_config(): void
    {
        $this->app['config']->set('brim.embeddings.ollama.host', 'http://custom:11434');
        $this->app['config']->set('brim.embeddings.ollama.model', 'all-minilm');

        // Create a fresh manager to pick up new config
        $manager = $this->app->make(EmbeddingManager::class);
        $driver = $manager->driver('ollama');

        $this->assertEquals('http://custom:11434', $driver->getHost());
        $this->assertEquals('all-minilm', $driver->modelName());
    }

    public function test_openai_driver_uses_config(): void
    {
        $this->app['config']->set('brim.embeddings.openai.model', 'text-embedding-3-large');

        $manager = $this->app->make(EmbeddingManager::class);
        $driver = $manager->driver('openai');

        $this->assertEquals('text-embedding-3-large', $driver->modelName());
    }

    public function test_switching_default_driver(): void
    {
        $this->app['config']->set('brim.embeddings.driver', 'ollama');
        $manager1 = $this->app->make(EmbeddingManager::class);
        $driver1 = $manager1->driver();
        $this->assertInstanceOf(OllamaDriver::class, $driver1);

        $this->app['config']->set('brim.embeddings.driver', 'openai');
        $manager2 = $this->app->make(EmbeddingManager::class);
        $driver2 = $manager2->driver();
        $this->assertInstanceOf(OpenAIDriver::class, $driver2);
    }
}
