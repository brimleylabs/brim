<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the embedding driver and models. Ollama is the default
    | local-first option, while OpenAI provides cloud-based embeddings.
    |
    */

    'embeddings' => [
        'driver' => env('BRIM_EMBEDDINGS_DRIVER', 'ollama'),

        'ollama' => [
            'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'nomic-embed-text'),
            'timeout' => 30,
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'timeout' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector Store Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the vector store used for storing and searching embeddings.
    | Currently only pgvector is supported.
    |
    */

    'vector_store' => [
        'driver' => 'pgvector',
        'table' => 'brim_embeddings',
        'connection' => null, // Use default database connection
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Sync
    |--------------------------------------------------------------------------
    |
    | When enabled, embeddings will be automatically generated when models
    | are saved. Set to false to manually control embedding generation.
    |
    */

    'auto_sync' => env('BRIM_AUTO_SYNC', true),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | When enabled, embedding generation will be dispatched to the queue
    | instead of running synchronously. Recommended for production.
    |
    */

    'queue' => env('BRIM_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | The number of models to process at a time when generating embeddings
    | in batch via the brim:embed command.
    |
    */

    'batch_size' => 50,

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Configure text chunking for long content. When enabled, long text
    | will be split into chunks that fit within the model's token limit.
    |
    */

    'chunking' => [
        'enabled' => true,
        'overlap_words' => 50, // Number of words to overlap between chunks
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for failed embedding requests. Uses
    | exponential backoff with jitter to prevent thundering herd.
    |
    */

    'retry' => [
        'max_attempts' => 3,
        'base_delay_ms' => 200,
        'max_delay_ms' => 5000,
        'multiplier' => 2.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Defaults
    |--------------------------------------------------------------------------
    |
    | Default values for semantic search queries.
    |
    */

    'search' => [
        'limit' => 10,
        'min_similarity' => 0.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure telemetry and observability features. When enabled, Brim will
    | dispatch events and optionally store metrics for debugging, performance
    | optimization, and monitoring.
    |
    */

    'telemetry' => [
        // Master toggle for all telemetry features
        'enabled' => env('BRIM_TELEMETRY_ENABLED', true),

        // Store telemetry entries in the database
        'store' => [
            'enabled' => env('BRIM_TELEMETRY_STORE', true),
            'table' => 'brim_telemetry',
            'retention_days' => 30, // Auto-prune entries older than this
        ],

        // Log telemetry to Laravel's logging system
        'logging' => [
            'enabled' => env('BRIM_TELEMETRY_LOG', false),
            'channel' => env('BRIM_TELEMETRY_LOG_CHANNEL', null), // null = default channel
            'level' => 'debug',
        ],

        // Debug mode - adds detailed timing to search results
        'debug' => env('BRIM_TELEMETRY_DEBUG', false),

        // What to track (granular control)
        'track' => [
            'embeddings' => true,    // Track embedding generation
            'searches' => true,      // Track search operations
            'batches' => true,       // Track batch operations
            'failures' => true,      // Track failures and retries
        ],

        // Sampling rate (0.0 to 1.0) - useful for high-traffic applications
        'sample_rate' => env('BRIM_TELEMETRY_SAMPLE_RATE', 1.0),
    ],

];
