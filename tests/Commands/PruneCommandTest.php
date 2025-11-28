<?php

namespace Brim\Tests\Commands;

use Brim\Stores\PgVectorStore;
use Brim\Tests\Fixtures\TestArticle;
use Brim\Tests\TestCase;
use Mockery;

/**
 * @group commands
 * @group prune
 */
class PruneCommandTest extends TestCase
{
    public function test_prune_command_shows_no_orphaned(): void
    {
        $mockStore = Mockery::mock(PgVectorStore::class);
        $mockStore->shouldReceive('findOrphaned')
            ->once()
            ->andReturn(collect());

        $this->app->instance(PgVectorStore::class, $mockStore);

        $this->artisan('brim:prune')
            ->expectsOutputToContain('No orphaned embeddings found')
            ->assertExitCode(0);
    }

    public function test_prune_command_dry_run_shows_orphaned(): void
    {
        $orphaned = collect([
            (object) ['id' => 1, 'model_type' => 'App\\Models\\Article', 'model_id' => 999],
            (object) ['id' => 2, 'model_type' => 'App\\Models\\Article', 'model_id' => 998],
            (object) ['id' => 3, 'model_type' => 'App\\Models\\Product', 'model_id' => 500],
        ]);

        $mockStore = Mockery::mock(PgVectorStore::class);
        $mockStore->shouldReceive('findOrphaned')
            ->once()
            ->andReturn($orphaned);
        // Should NOT delete in dry-run
        $mockStore->shouldNotReceive('deleteOrphaned');

        $this->app->instance(PgVectorStore::class, $mockStore);

        $this->artisan('brim:prune', ['--dry-run' => true])
            ->expectsOutputToContain('Found 3 orphaned')
            ->expectsOutputToContain('Dry run mode')
            ->assertExitCode(0);
    }

    public function test_prune_command_deletes_orphaned_when_confirmed(): void
    {
        $orphaned = collect([
            (object) ['id' => 1, 'model_type' => TestArticle::class, 'model_id' => 999],
        ]);

        $mockStore = Mockery::mock(PgVectorStore::class);
        $mockStore->shouldReceive('findOrphaned')
            ->once()
            ->andReturn($orphaned);
        $mockStore->shouldReceive('deleteOrphaned')
            ->once()
            ->andReturn(1);

        $this->app->instance(PgVectorStore::class, $mockStore);

        $this->artisan('brim:prune')
            ->expectsConfirmation('Do you want to delete these orphaned embeddings?', 'yes')
            ->expectsOutputToContain('Successfully deleted 1')
            ->assertExitCode(0);
    }

    public function test_prune_command_cancels_when_not_confirmed(): void
    {
        $orphaned = collect([
            (object) ['id' => 1, 'model_type' => TestArticle::class, 'model_id' => 999],
        ]);

        $mockStore = Mockery::mock(PgVectorStore::class);
        $mockStore->shouldReceive('findOrphaned')
            ->once()
            ->andReturn($orphaned);
        // Should NOT delete when not confirmed
        $mockStore->shouldNotReceive('deleteOrphaned');

        $this->app->instance(PgVectorStore::class, $mockStore);

        $this->artisan('brim:prune')
            ->expectsConfirmation('Do you want to delete these orphaned embeddings?', 'no')
            ->expectsOutputToContain('Operation cancelled')
            ->assertExitCode(0);
    }
}
