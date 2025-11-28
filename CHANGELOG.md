# Changelog

All notable changes to Brim will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-11-27

### Added
- Initial release of Brim
- `HasEmbeddings` trait for Eloquent models
- Ollama embedding driver (local, no API keys)
- OpenAI embedding driver (cloud option)
- pgvector vector store with HNSW indexing
- `semanticSearch()` query scope for semantic queries
- `findSimilar()` method for finding related models
- Automatic embedding sync via model observers
- Queue support for background embedding generation
- Namespaced embeddings for multiple embedding types per model
- Telemetry and observability system
- Artisan commands: `brim:health`, `brim:embed`, `brim:telemetry`
- Comprehensive configuration options
- Laravel 10, 11, and 12 support

### Security
- All data processed locally with Ollama driver
- No external API calls unless OpenAI driver is explicitly configured
