<?php

namespace Brim\Tests\Commands;

use Brim\Brim;
use Brim\Tests\TestCase;
use Mockery;

/**
 * @group commands
 * @group status
 */
class StatusCommandTest extends TestCase
{
    public function test_status_command_displays_driver_info(): void
    {
        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('healthCheck')
            ->once()
            ->andReturn([
                'healthy' => true,
                'message' => 'Ollama running, model [nomic-embed-text] available',
                'details' => [
                    'host' => 'http://localhost:11434',
                    'model' => 'nomic-embed-text',
                    'model_installed' => true,
                ],
            ]);

        $mockBrim->shouldReceive('stats')
            ->once()
            ->andReturn([
                'total' => 100,
                'models' => 25,
                'by_type' => [],
            ]);

        $this->app->instance(Brim::class, $mockBrim);

        $this->artisan('brim:status')
            ->expectsOutputToContain('Brim Status')
            ->expectsOutputToContain('Healthy')
            ->expectsOutputToContain('nomic-embed-text')
            ->assertExitCode(0);
    }

    public function test_status_command_shows_unhealthy_status(): void
    {
        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('healthCheck')
            ->once()
            ->andReturn([
                'healthy' => false,
                'message' => 'Cannot connect to Ollama',
                'details' => [
                    'host' => 'http://localhost:11434',
                    'error' => 'Connection refused',
                ],
            ]);

        $mockBrim->shouldReceive('stats')
            ->once()
            ->andReturn([
                'total' => 0,
                'models' => 0,
                'by_type' => [],
            ]);

        $this->app->instance(Brim::class, $mockBrim);

        $this->artisan('brim:status')
            ->expectsOutputToContain('Unhealthy')
            ->assertExitCode(0);
    }

    public function test_status_command_shows_embedding_statistics(): void
    {
        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('healthCheck')
            ->once()
            ->andReturn([
                'healthy' => true,
                'message' => 'OK',
                'details' => [],
            ]);

        $mockBrim->shouldReceive('stats')
            ->once()
            ->andReturn([
                'total' => 500,
                'models' => 100,
                'by_type' => [
                    'App\\Models\\Article' => [
                        'models' => 50,
                        'embeddings' => 250,
                    ],
                    'App\\Models\\Product' => [
                        'models' => 50,
                        'embeddings' => 250,
                    ],
                ],
            ]);

        $this->app->instance(Brim::class, $mockBrim);

        $this->artisan('brim:status')
            ->expectsOutputToContain('500')
            ->expectsOutputToContain('100')
            ->assertExitCode(0);
    }

    public function test_status_command_handles_stats_exception(): void
    {
        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('healthCheck')
            ->once()
            ->andReturn([
                'healthy' => true,
                'message' => 'OK',
                'details' => [],
            ]);

        $mockBrim->shouldReceive('stats')
            ->once()
            ->andThrow(new \RuntimeException('Database connection error'));

        $this->app->instance(Brim::class, $mockBrim);

        $this->artisan('brim:status')
            ->expectsOutputToContain('Could not fetch embedding statistics')
            ->assertExitCode(0);
    }
}
