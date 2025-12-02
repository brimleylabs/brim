<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable pgvector extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('brim_embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('model_type', 255);
            $table->unsignedBigInteger('model_id');
            $table->integer('chunk_index')->default(0);
            $table->string('namespace', 255)->nullable();
            $table->string('embedding_model', 100)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamps();

            // Unique constraint for model + chunk
            $table->unique(['model_type', 'model_id', 'chunk_index'], 'brim_model_chunk_unique');

            // Index for filtering by type and namespace
            $table->index(['model_type', 'namespace'], 'brim_type_namespace_idx');
        });

        // Add vector column (768 dimensions for nomic-embed-text default)
        DB::statement('ALTER TABLE brim_embeddings ADD COLUMN embedding vector(768)');

        // Create HNSW index for fast similarity search
        DB::statement('CREATE INDEX brim_embedding_hnsw_idx ON brim_embeddings USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brim_embeddings');
    }
};
