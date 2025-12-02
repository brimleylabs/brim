<?php

namespace Brim\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void generateFor(\Illuminate\Database\Eloquent\Model $model)
 * @method static void queueFor(\Illuminate\Database\Eloquent\Model $model)
 * @method static void generateOrQueueFor(\Illuminate\Database\Eloquent\Model $model)
 * @method static void deleteFor(\Illuminate\Database\Eloquent\Model $model)
 * @method static \Illuminate\Support\Collection search(string $modelClass, string $query, ?int $limit = null, ?float $minSimilarity = null, ?string $namespace = null)
 * @method static bool existsFor(\Illuminate\Database\Eloquent\Model $model)
 * @method static int chunkCountFor(\Illuminate\Database\Eloquent\Model $model)
 * @method static \Brim\Contracts\EmbeddingDriver driver(?string $name = null)
 * @method static \Brim\Contracts\VectorStore store(?string $name = null)
 * @method static array stats()
 * @method static array healthCheck(?string $driver = null)
 *
 * @see \Brim\Brim
 */
class Brim extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \Brim\Brim::class;
    }
}
