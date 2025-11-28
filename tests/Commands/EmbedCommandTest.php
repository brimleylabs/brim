<?php

namespace Brim\Tests\Commands;

use Brim\Brim;
use Brim\Tests\Fixtures\TestArticle;
use Brim\Tests\TestCase;
use Mockery;

/**
 * @group commands
 * @group embed
 */
class EmbedCommandTest extends TestCase
{
    public function test_embed_command_validates_model_class_exists(): void
    {
        $this->artisan('brim:embed', ['model' => 'NonExistent\\Model\\Class'])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);
    }

    public function test_embed_command_validates_model_is_eloquent(): void
    {
        // Use a class that exists but is not an Eloquent model
        $this->artisan('brim:embed', ['model' => \stdClass::class])
            ->expectsOutputToContain('is not an Eloquent model')
            ->assertExitCode(1);
    }

    public function test_embed_command_handles_no_records(): void
    {
        // TestArticle implements Embeddable so it passes validation
        $this->artisan('brim:embed', ['model' => TestArticle::class])
            ->assertExitCode(0);
    }

    public function test_embed_command_processes_records(): void
    {
        // Create test articles
        $this->createTestArticle(['title' => 'Article 1']);
        $this->createTestArticle(['title' => 'Article 2']);

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('existsFor')
            ->times(2)
            ->andReturn(false);
        $mockBrim->shouldReceive('generateFor')
            ->times(2);

        $this->app->instance(Brim::class, $mockBrim);

        $this->artisan('brim:embed', ['model' => TestArticle::class])
            ->expectsOutputToContain('Generating embeddings')
            ->expectsOutputToContain('Embedding generation complete')
            ->assertExitCode(0);
    }

    public function test_embed_command_skips_existing_embeddings(): void
    {
        $this->createTestArticle(['title' => 'Article 1']);
        $this->createTestArticle(['title' => 'Article 2']);

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('existsFor')
            ->times(2)
            ->andReturn(true); // All have embeddings
        $mockBrim->shouldNotReceive('generateFor');

        $this->app->instance(Brim::class, $mockBrim);

        $this->artisan('brim:embed', ['model' => TestArticle::class])
            ->expectsOutputToContain('Skipped (existing)')
            ->expectsOutputToContain('2')
            ->assertExitCode(0);
    }

    public function test_embed_command_force_regenerates(): void
    {
        $this->createTestArticle(['title' => 'Article 1']);

        $mockBrim = Mockery::mock(Brim::class);
        // Should not check existsFor when --force is used
        $mockBrim->shouldReceive('existsFor')->never();
        $mockBrim->shouldReceive('generateFor')->once();

        $this->app->instance(Brim::class, $mockBrim);

        $this->artisan('brim:embed', [
            'model' => TestArticle::class,
            '--force' => true,
        ])
            ->assertExitCode(0);
    }

    public function test_embed_command_handles_errors(): void
    {
        $this->createTestArticle(['title' => 'Article 1']);
        $this->createTestArticle(['title' => 'Article 2']);

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('existsFor')
            ->times(2)
            ->andReturn(false);
        $mockBrim->shouldReceive('generateFor')
            ->twice()
            ->andThrow(new \RuntimeException('API error'));

        $this->app->instance(Brim::class, $mockBrim);

        $this->artisan('brim:embed', ['model' => TestArticle::class])
            ->expectsOutputToContain('Errors')
            ->expectsOutputToContain('2')
            ->assertExitCode(1);
    }

    public function test_embed_command_uses_batch_size_option(): void
    {
        // Create 5 articles
        for ($i = 0; $i < 5; $i++) {
            $this->createTestArticle(['title' => "Article {$i}"]);
        }

        $mockBrim = Mockery::mock(Brim::class);
        $mockBrim->shouldReceive('existsFor')->andReturn(false);
        $mockBrim->shouldReceive('generateFor');

        $this->app->instance(Brim::class, $mockBrim);

        // Use batch size of 2
        $this->artisan('brim:embed', [
            'model' => TestArticle::class,
            '--batch' => 2,
        ])
            ->assertExitCode(0);
    }
}
