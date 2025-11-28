<p align="center">
  <img src="https://raw.githubusercontent.com/brimleylabs/brim/main/art/brimley.svg" width="140" alt="Brimley the Morkie">
</p>

<h1 align="center">Brim</h1>

<p align="center">
  <strong>Semantic Search for Laravel</strong><br>
  Local-first. Privacy-focused. Zero API costs.
</p>

<p align="center">
  <a href="https://packagist.org/packages/brimleylabs/brim"><img src="https://img.shields.io/packagist/v/brimleylabs/brim.svg?style=flat-square" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/brimleylabs/brim"><img src="https://img.shields.io/packagist/dt/brimleylabs/brim.svg?style=flat-square" alt="Total Downloads"></a>
  <a href="https://github.com/brimleylabs/brim/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square" alt="License"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/php-8.2+-8892BF.svg?style=flat-square" alt="PHP Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/laravel-10.x--12.x-FF2D20.svg?style=flat-square" alt="Laravel Version"></a>
</p>

<p align="center">
  <a href="#-quick-start">Quick Start</a> •
  <a href="#-installation">Installation</a> •
  <a href="#-usage">Usage</a> •
  <a href="#-configuration">Configuration</a> •
  <a href="#-advanced">Advanced</a>
</p>

---

## What is Brim?

Brim adds **AI-powered semantic search** to your Laravel models. Instead of matching exact keywords, Brim understands the *meaning* behind your queries.

```php
// Traditional search: only finds exact matches
Article::where('title', 'LIKE', '%red car%')->get();

// Brim semantic search: understands meaning
Article::semanticSearch('red car')->get();
// ✓ Finds: "crimson automobile", "scarlet vehicle", "cherry-colored sedan"
```

**How it works:** Brim converts your text into numerical vectors (embeddings) using AI, then finds similar content using vector mathematics. All processing happens locally on your machine using [Ollama](https://ollama.ai) - no API keys, no usage limits, no data leaving your server.

---

## ✨ Why Brim?

<table>
<tr>
<td width="50%">

### 🔒 Privacy First
Your data **never leaves your server**. Embeddings are generated locally using Ollama. No third-party API calls unless you explicitly choose OpenAI.

### 💰 Zero API Costs
No per-query charges. No token limits. Generate unlimited embeddings and run unlimited searches.

### ⚡ Production Ready
HNSW indexing for sub-millisecond searches. Batch operations. Queue support. Built-in telemetry.

</td>
<td width="50%">

### 🎯 Laravel Native
Eloquent trait integration. Familiar syntax. Model observers for automatic sync. Works with your existing codebase.

### 🔌 Extensible
Swap between Ollama (local) and OpenAI (cloud). Custom drivers supported. Multiple embedding namespaces per model.

### 📊 Observable
Built-in telemetry tracks embedding generation, search performance, and system health.

</td>
</tr>
</table>

---

## 🚀 Quick Start

Get semantic search running in under 5 minutes:

```bash
composer require brimleylabs/brim
php artisan vendor:publish --provider="Brim\BrimServiceProvider"
php artisan migrate
```

```php
// 1. Add trait to your model
class Article extends Model
{
    use HasEmbeddings;

    public function toEmbeddableText(): string
    {
        return "{$this->title}\n\n{$this->content}";
    }
}

// 2. Generate embeddings
Article::all()->each->generateEmbedding();

// 3. Search semantically
Article::semanticSearch('climate change solutions')->get();
```

That's it. Your app now has AI-powered search.

---

## 📋 Requirements

| Requirement | Version | Notes |
|-------------|---------|-------|
| PHP | 8.2+ | Required |
| Laravel | 10.x, 11.x, 12.x | Any recent version |
| PostgreSQL | 12+ | With pgvector extension |
| Ollama | Latest | For local embeddings |

---

## 📦 Installation

### Step 1: Install the Package

```bash
composer require brimleylabs/brim
```

### Step 2: Publish Configuration & Migrations

```bash
php artisan vendor:publish --provider="Brim\BrimServiceProvider"
```

This publishes:
- `config/brim.php` - Configuration file
- `database/migrations/*_create_brim_embeddings_table.php` - Vector storage table
- `database/migrations/*_create_brim_telemetry_table.php` - Telemetry table (optional)

### Step 3: Configure PostgreSQL with pgvector

Brim uses pgvector for efficient vector storage and similarity search.

**Option A: If you have superuser access:**
```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

**Option B: Using Docker (recommended for local development):**
```yaml
# docker-compose.yml
services:
  postgres:
    image: pgvector/pgvector:pg16
    environment:
      POSTGRES_DB: your_database
      POSTGRES_USER: your_user
      POSTGRES_PASSWORD: your_password
    ports:
      - "5432:5432"
```

**Option C: Cloud PostgreSQL:**
- **Supabase**: pgvector enabled by default
- **Neon**: Enable via dashboard extensions
- **AWS RDS**: Enable pgvector extension in parameter group

### Step 4: Run Migrations

```bash
php artisan migrate
```

### Step 5: Install Ollama

Ollama runs AI models locally on your machine.

**macOS:**
```bash
brew install ollama
```

**Linux:**
```bash
curl -fsSL https://ollama.ai/install.sh | sh
```

**Windows:**
Download from [ollama.ai/download](https://ollama.ai/download)

### Step 6: Pull the Embedding Model

```bash
ollama pull nomic-embed-text
```

This downloads the `nomic-embed-text` model (~274MB), optimized for generating text embeddings.

### Step 7: Start Ollama

```bash
ollama serve
```

Ollama runs on `http://localhost:11434` by default.

### Step 8: Verify Installation

```bash
php artisan brim:health
```

You should see:
```
✓ Ollama connection: healthy
✓ Model nomic-embed-text: available
✓ PostgreSQL pgvector: enabled
✓ Brim is ready to use!
```

---

## 🎯 Usage

### Adding Semantic Search to a Model

**Step 1:** Add the `HasEmbeddings` trait and implement `toEmbeddableText()`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Brim\Traits\HasEmbeddings;

class Article extends Model
{
    use HasEmbeddings;

    /**
     * Define what text should be embedded for this model.
     * This is what Brim uses to understand your content.
     */
    public function toEmbeddableText(): string
    {
        return implode("\n\n", [
            "Title: {$this->title}",
            "Summary: {$this->summary}",
            "Content: {$this->content}",
            "Tags: " . $this->tags->pluck('name')->implode(', '),
        ]);
    }
}
```

**Tips for `toEmbeddableText()`:**
- Include all semantically relevant fields
- Add context labels ("Title:", "Author:") for better results
- Include related data (tags, categories, author names)
- Keep it under ~8000 characters (longer text is automatically chunked)

### Generating Embeddings

**Single model:**
```php
$article = Article::find(1);
$article->generateEmbedding();
```

**All models:**
```php
Article::all()->each->generateEmbedding();
```

**With progress (for large datasets):**
```php
Article::chunk(100, function ($articles) {
    foreach ($articles as $article) {
        $article->generateEmbedding();
        echo "Embedded: {$article->title}\n";
    }
});
```

**Using Artisan command:**
```bash
# Embed all articles
php artisan brim:embed "App\Models\Article"

# Embed with progress bar
php artisan brim:embed "App\Models\Article" --progress
```

### Searching

**Basic semantic search:**
```php
$results = Article::semanticSearch('machine learning tutorials for beginners')
    ->take(10)
    ->get();
```

**With minimum similarity threshold:**
```php
$results = Article::semanticSearch('quantum computing', minSimilarity: 0.7)
    ->get();
```

**Access similarity scores:**
```php
$results = Article::semanticSearch('sustainable energy')->get();

foreach ($results as $article) {
    echo "{$article->title}\n";
    echo "Relevance: " . round($article->brim_similarity * 100) . "%\n";
}
```

**Combine with Eloquent queries:**
```php
$results = Article::semanticSearch('healthy recipes')
    ->where('published', true)
    ->where('created_at', '>', now()->subMonth())
    ->with(['author', 'tags'])
    ->take(20)
    ->get();
```

### Finding Similar Models

```php
$article = Article::find(1);

// Get 5 similar articles
$similar = $article->findSimilar(5);

// With minimum similarity
$similar = $article->findSimilar(10, minSimilarity: 0.6);
```

### Checking Embedding Status

```php
// Check if a model has an embedding
if ($article->hasEmbedding()) {
    echo "Ready for semantic search";
}

// Get embedding metadata
$embedding = $article->embedding;
echo "Dimensions: {$embedding->dimensions}";
echo "Created: {$embedding->created_at}";
```

---

## ⚙️ Configuration

After publishing, edit `config/brim.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Embedding Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "ollama", "openai"
    |
    */
    'embedding' => [
        'driver' => env('BRIM_EMBEDDING_DRIVER', 'ollama'),

        'ollama' => [
            'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'nomic-embed-text'),
            'timeout' => 120,
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'dimensions' => 1536,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector Store
    |--------------------------------------------------------------------------
    */
    'vector_store' => [
        'driver' => 'pgvector',
        'table' => 'brim_embeddings',
        'dimensions' => 768, // nomic-embed-text dimensions
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Defaults
    |--------------------------------------------------------------------------
    */
    'search' => [
        'default_limit' => 10,
        'min_similarity' => 0.5,  // 0-1 scale
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Enable to process embeddings in the background.
    |
    */
    'queue' => [
        'enabled' => env('BRIM_QUEUE_ENABLED', false),
        'connection' => env('BRIM_QUEUE_CONNECTION', 'default'),
        'queue' => env('BRIM_QUEUE_NAME', 'embeddings'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Sync
    |--------------------------------------------------------------------------
    |
    | Automatically regenerate embeddings when models are updated.
    |
    */
    'auto_sync' => [
        'enabled' => env('BRIM_AUTO_SYNC', true),
        'on_create' => true,
        'on_update' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | Track embedding and search performance metrics.
    |
    */
    'telemetry' => [
        'enabled' => env('BRIM_TELEMETRY_ENABLED', true),
        'store' => [
            'enabled' => true,
            'table' => 'brim_telemetry',
            'retention_days' => 30,
        ],
    ],
];
```

### Environment Variables

Add to your `.env`:

```env
# Embedding Driver (ollama or openai)
BRIM_EMBEDDING_DRIVER=ollama

# Ollama Configuration
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=nomic-embed-text

# OpenAI Configuration (if using openai driver)
OPENAI_API_KEY=sk-...
OPENAI_EMBEDDING_MODEL=text-embedding-3-small

# Queue Processing
BRIM_QUEUE_ENABLED=false
BRIM_QUEUE_CONNECTION=redis
BRIM_QUEUE_NAME=embeddings

# Auto Sync
BRIM_AUTO_SYNC=true

# Telemetry
BRIM_TELEMETRY_ENABLED=true
```

---

## 🔧 Advanced Features

### Namespaced Embeddings

Store multiple embedding types per model for different search contexts:

```php
class Product extends Model
{
    use HasEmbeddings;

    public function toEmbeddableText(): string
    {
        return $this->name . "\n" . $this->description;
    }
}

// Generate embeddings for different aspects
$product->generateEmbedding(); // Default namespace
$product->generateEmbedding('reviews');  // Customer reviews
$product->generateEmbedding('specs');    // Technical specifications

// Search specific namespaces
Product::semanticSearch('comfortable for long gaming sessions', namespace: 'reviews')->get();
Product::semanticSearch('RGB lighting support', namespace: 'specs')->get();
```

### Queue Processing

For large datasets, process embeddings in the background:

```php
// config/brim.php
'queue' => [
    'enabled' => true,
    'connection' => 'redis',
    'queue' => 'embeddings',
],
```

```php
// Embeddings are now queued automatically
$article->generateEmbedding(); // Dispatches job

// Or dispatch manually
use Brim\Jobs\GenerateEmbedding;

GenerateEmbedding::dispatch($article);
```

Run the queue worker:
```bash
php artisan queue:work --queue=embeddings
```

### Automatic Sync

By default, embeddings regenerate when models are updated:

```php
$article->title = 'Updated Title';
$article->save(); // Embedding automatically regenerates
```

Disable per-model:
```php
class Article extends Model
{
    use HasEmbeddings;

    protected bool $brimAutoSync = false;
}
```

Or globally in config:
```php
'auto_sync' => [
    'enabled' => false,
],
```

### Telemetry & Observability

Monitor embedding and search performance:

```php
// Get stats
$stats = app('brim.telemetry')->getStats('24h');

/*
[
    'embeddings' => [
        'count' => 150,
        'avg_duration_ms' => 245.5,
        'total_chunks' => 892,
    ],
    'searches' => [
        'count' => 1024,
        'avg_duration_ms' => 45.2,
        'avg_results' => 8.5,
    ],
]
*/
```

Listen to events:
```php
use Brim\Events\BrimEmbeddingCompleted;
use Brim\Events\BrimSearchCompleted;

// In EventServiceProvider
protected $listen = [
    BrimEmbeddingCompleted::class => [
        LogEmbeddingMetrics::class,
    ],
    BrimSearchCompleted::class => [
        TrackSearchAnalytics::class,
    ],
];
```

### Text Chunking

Long text is automatically chunked for better embedding quality:

```php
// config/brim.php
'chunking' => [
    'enabled' => true,
    'max_length' => 2000,    // Characters per chunk
    'overlap' => 200,        // Overlap between chunks
],
```

### Custom Embedding Drivers

Create your own embedding driver:

```php
use Brim\Contracts\EmbeddingDriver;

class CustomDriver implements EmbeddingDriver
{
    public function embed(string $text): array
    {
        // Return array of floats (embedding vector)
        return $this->yourEmbeddingLogic($text);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn($text) => $this->embed($text), $texts);
    }

    public function dimensions(): int
    {
        return 768;
    }
}

// Register in a service provider
$this->app->bind('brim.embedding.custom', CustomDriver::class);
```

---

## 🛠️ Artisan Commands

```bash
# Check system health and connectivity
php artisan brim:health

# Generate embeddings for a model
php artisan brim:embed "App\Models\Article"
php artisan brim:embed "App\Models\Article" --progress
php artisan brim:embed "App\Models\Article" --queue

# View telemetry statistics
php artisan brim:telemetry stats
php artisan brim:telemetry stats --period=7d

# Prune old telemetry data
php artisan brim:telemetry prune
php artisan brim:telemetry prune --days=7

# Prune orphaned embeddings
php artisan brim:prune
```

---

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        YOUR LARAVEL APP                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Model + HasEmbeddings Trait                                   │
│   ┌───────────────────────────────────────────────────────────┐ │
│   │  toEmbeddableText()    →  Define content to embed         │ │
│   │  generateEmbedding()   →  Create/update embedding         │ │
│   │  semanticSearch()      →  Query scope for searching       │ │
│   │  findSimilar()         →  Get related models              │ │
│   └───────────────────────────────────────────────────────────┘ │
│                               │                                 │
├───────────────────────────────┼─────────────────────────────────┤
│                               ▼                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │                     BRIM SERVICE                        │   │
│   │  ┌─────────────────────┐   ┌─────────────────────────┐  │   │
│   │  │  EmbeddingManager   │   │  VectorStoreManager     │  │   │
│   │  │  ┌───────────────┐  │   │  ┌───────────────────┐  │  │   │
│   │  │  │ OllamaDriver  │  │   │  │  PgVectorStore    │  │  │   │
│   │  │  │ OpenAIDriver  │  │   │  │  (extensible)     │  │  │   │
│   │  │  └───────────────┘  │   │  └───────────────────┘  │  │   │
│   │  └─────────────────────┘   └─────────────────────────┘  │   │
│   └─────────────────────────────────────────────────────────┘   │
│                               │                                 │
├───────────────────────────────┼─────────────────────────────────┤
│                               ▼                                 │
│   ┌─────────────────────┐         ┌───────────────────────────┐ │
│   │   OLLAMA (local)    │         │  POSTGRESQL + PGVECTOR    │ │
│   │   - nomic-embed     │         │  - HNSW indexing          │ │
│   │   - runs on device  │         │  - Cosine similarity      │ │
│   │   - no API keys     │         │  - Sub-ms queries         │ │
│   └─────────────────────┘         └───────────────────────────┘ │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 💡 Real-World Examples

### E-Commerce Product Search

```php
class Product extends Model
{
    use HasEmbeddings;

    public function toEmbeddableText(): string
    {
        return implode("\n", [
            $this->name,
            $this->description,
            "Category: {$this->category->name}",
            "Brand: {$this->brand}",
            "Features: " . implode(', ', $this->features),
        ]);
    }
}

// "comfortable chair for working from home" finds:
// - Ergonomic Office Chair
// - Home Office Desk Chair with Lumbar Support
// - Executive Chair with Adjustable Armrests
```

### Documentation Search

```php
class DocPage extends Model
{
    use HasEmbeddings;

    public function toEmbeddableText(): string
    {
        return "# {$this->title}\n\n{$this->content}";
    }
}

// "how do I authenticate users" finds:
// - Authentication Guide
// - Login Implementation
// - Session Management
// - OAuth Integration
```

### Support Ticket Matching

```php
// Find similar resolved tickets
$newTicket = Ticket::create(['issue' => 'App crashes when uploading large files']);

$similarResolved = Ticket::semanticSearch($newTicket->issue)
    ->where('status', 'resolved')
    ->take(5)
    ->get();

// Suggest solutions based on similar past tickets
```

### Content Recommendations

```php
// "More like this" feature
$article = Article::find(1);
$recommendations = $article->findSimilar(6)
    ->where('id', '!=', $article->id);
```

---

## ❓ FAQ

<details>
<summary><strong>How is this different from Algolia/Meilisearch?</strong></summary>

Traditional search engines use keyword matching and require careful index configuration. Brim uses AI embeddings to understand semantic meaning - no configuration needed, just define what text to embed.

| Feature | Algolia/Meilisearch | Brim |
|---------|---------------------|------|
| Search type | Keyword matching | Semantic understanding |
| Setup | Complex index config | Add trait + one method |
| Typo tolerance | Configured rules | Understands meaning |
| Synonyms | Manual dictionary | Automatic |
| Cost | Per-search pricing | Free (local) |
| Privacy | Data on their servers | Your server only |
</details>

<details>
<summary><strong>Can I use MySQL instead of PostgreSQL?</strong></summary>

Currently, Brim requires PostgreSQL with the pgvector extension for vector storage. pgvector provides optimized vector operations and HNSW indexing that aren't available in MySQL.
</details>

<details>
<summary><strong>How much does Ollama slow down my machine?</strong></summary>

Embedding generation uses your CPU/GPU but only during the embedding process. Searches are fast database queries. For production with many concurrent embeddings, consider using queue workers on separate processes.
</details>

<details>
<summary><strong>Can I use OpenAI instead of Ollama?</strong></summary>

Yes! Change your config:
```env
BRIM_EMBEDDING_DRIVER=openai
OPENAI_API_KEY=sk-...
```
Note: This sends your text to OpenAI's servers for embedding.
</details>

<details>
<summary><strong>How do I handle models with very long text?</strong></summary>

Brim automatically chunks long text. Configure in `config/brim.php`:
```php
'chunking' => [
    'max_length' => 2000,
    'overlap' => 200,
],
```
</details>

---

## 🐛 Troubleshooting

### "Connection refused" to Ollama

```bash
# Make sure Ollama is running
ollama serve

# Check it's accessible
curl http://localhost:11434/api/tags
```

### "Model not found"

```bash
# Pull the model
ollama pull nomic-embed-text

# Verify it's available
ollama list
```

### "pgvector extension not found"

```sql
-- Connect to your database and run:
CREATE EXTENSION IF NOT EXISTS vector;

-- Verify:
SELECT * FROM pg_extension WHERE extname = 'vector';
```

### Embeddings not generating

```php
// Check if the trait is properly added
$article = Article::first();
dd(method_exists($article, 'generateEmbedding')); // Should be true

// Check toEmbeddableText returns content
dd($article->toEmbeddableText()); // Should show text
```

---

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 🔒 Security

If you discover a security vulnerability, please email openwestlabs@gmail.com instead of using the issue tracker.

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## 🐕 Why "Brim"?

Named after Brimley, the goodest boy who inspired this project. Like a loyal companion, Brim faithfully retrieves what you're looking for - understanding not just what you say, but what you mean.

## 👨‍💻 Credits

- [Matthew Summers](https://github.com/brimleylabs)
- [All Contributors](../../contributors)

## 📄 License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

---

<p align="center">
  <sub>Built with ❤️ for the Laravel community</sub>
</p>
