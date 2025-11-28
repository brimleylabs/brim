<?php

namespace Brim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Embedding extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'brim_embeddings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'model_type',
        'model_id',
        'chunk_index',
        'namespace',
        'embedding_model',
        'content_hash',
        'embedding',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'model_id' => 'integer',
        'chunk_index' => 'integer',
    ];

    /**
     * Get the parent embeddable model.
     *
     * @return MorphTo
     */
    public function embeddable(): MorphTo
    {
        return $this->morphTo('embeddable', 'model_type', 'model_id');
    }

    /**
     * Scope to filter by model type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $modelType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForType($query, string $modelType)
    {
        return $query->where('model_type', $modelType);
    }

    /**
     * Scope to filter by namespace.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $namespace
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInNamespace($query, ?string $namespace)
    {
        if ($namespace === null) {
            return $query->whereNull('namespace');
        }

        return $query->where('namespace', $namespace);
    }

    /**
     * Scope to filter by embedding model.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $model
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUsingModel($query, string $model)
    {
        return $query->where('embedding_model', $model);
    }

    /**
     * Get the table name from config.
     *
     * @return string
     */
    public function getTable(): string
    {
        return config('brim.vector_store.table', 'brim_embeddings');
    }

    /**
     * Get the connection name from config.
     *
     * @return string|null
     */
    public function getConnectionName(): ?string
    {
        return config('brim.vector_store.connection');
    }
}
