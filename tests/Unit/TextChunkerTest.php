<?php

namespace Brim\Tests\Unit;

use Brim\Support\TextChunker;
use Brim\Tests\TestCase;

/**
 * @group unit
 * @group chunking
 */
class TextChunkerTest extends TestCase
{
    private TextChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new TextChunker([
            'enabled' => true,
            'overlap_words' => 50,
        ]);
    }

    public function test_short_text_returns_single_chunk(): void
    {
        $text = 'This is a short text that does not need chunking.';
        $chunks = $this->chunker->chunk($text, 'nomic-embed-text');

        $this->assertCount(1, $chunks);
        $this->assertEquals($text, $chunks[0]);
    }

    public function test_empty_text_returns_single_chunk(): void
    {
        $chunks = $this->chunker->chunk('', 'nomic-embed-text');

        $this->assertCount(1, $chunks);
        $this->assertEquals('', $chunks[0]);
    }

    public function test_text_is_chunked_when_exceeds_token_limit(): void
    {
        // Create text that exceeds token limit for all-minilm (256 tokens)
        // ~4 chars per token, so 256 tokens ≈ 1024 chars
        // Using all-minilm which has low token limit
        $words = [];
        for ($i = 0; $i < 500; $i++) {
            $words[] = 'word' . $i;
        }
        $longText = implode(' ', $words);

        $chunks = $this->chunker->chunk($longText, 'all-minilm');

        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_chunks_have_overlap(): void
    {
        // Create text that will definitely be chunked
        $words = [];
        for ($i = 0; $i < 500; $i++) {
            $words[] = 'word' . $i;
        }
        $longText = implode(' ', $words);

        $chunks = $this->chunker->chunk($longText, 'all-minilm');

        if (count($chunks) > 1) {
            // Check that last words of first chunk appear in second chunk
            $firstChunkWords = explode(' ', $chunks[0]);
            $secondChunkWords = explode(' ', $chunks[1]);

            $lastWordsOfFirst = array_slice($firstChunkWords, -50);
            $overlap = array_intersect($lastWordsOfFirst, $secondChunkWords);

            $this->assertNotEmpty($overlap, 'Chunks should have overlapping words');
        }
    }

    public function test_needs_chunking_returns_true_for_long_text(): void
    {
        $longText = str_repeat('word ', 5000);

        $this->assertTrue($this->chunker->needsChunking($longText, 'all-minilm'));
    }

    public function test_needs_chunking_returns_false_for_short_text(): void
    {
        $shortText = 'This is a short text.';

        $this->assertFalse($this->chunker->needsChunking($shortText, 'nomic-embed-text'));
    }

    public function test_needs_chunking_returns_false_when_disabled(): void
    {
        $disabledChunker = new TextChunker(['enabled' => false]);
        $longText = str_repeat('word ', 5000);

        $this->assertFalse($disabledChunker->needsChunking($longText, 'nomic-embed-text'));
    }

    public function test_estimate_tokens(): void
    {
        $text = 'This is a test sentence.'; // 24 characters
        $tokens = $this->chunker->estimateTokens($text);

        // ~4 chars per token
        $this->assertEquals(6, $tokens);
    }

    /**
     * @dataProvider tokenLimitProvider
     */
    public function test_get_token_limit_for_model(string $model, int $expected): void
    {
        $this->assertEquals($expected, $this->chunker->getTokenLimit($model));
    }

    public static function tokenLimitProvider(): array
    {
        return [
            'nomic-embed-text' => ['nomic-embed-text', 8192],
            'all-minilm' => ['all-minilm', 256],
            'text-embedding-3-small' => ['text-embedding-3-small', 8191],
            'unknown model returns default' => ['unknown-model', 8192],
        ];
    }

    /**
     * @dataProvider dimensionsProvider
     */
    public function test_get_dimensions_for_model(string $model, int $expected): void
    {
        $this->assertEquals($expected, $this->chunker->getDimensions($model));
    }

    public static function dimensionsProvider(): array
    {
        return [
            'nomic-embed-text' => ['nomic-embed-text', 768],
            'all-minilm' => ['all-minilm', 384],
            'text-embedding-3-small' => ['text-embedding-3-small', 1536],
            'text-embedding-3-large' => ['text-embedding-3-large', 3072],
            'unknown model returns default' => ['unknown-model', 768],
        ];
    }

    /**
     * @dataProvider knownModelProvider
     */
    public function test_is_known_model(string $model, bool $expected): void
    {
        $this->assertEquals($expected, $this->chunker->isKnownModel($model));
    }

    public static function knownModelProvider(): array
    {
        return [
            'nomic-embed-text is known' => ['nomic-embed-text', true],
            'text-embedding-3-small is known' => ['text-embedding-3-small', true],
            'unknown-model is not known' => ['unknown-model', false],
        ];
    }

    public function test_get_model_specs(): void
    {
        $specs = $this->chunker->getModelSpecs();

        $this->assertIsArray($specs);
        $this->assertArrayHasKey('nomic-embed-text', $specs);
        $this->assertArrayHasKey('dimensions', $specs['nomic-embed-text']);
        $this->assertArrayHasKey('token_limit', $specs['nomic-embed-text']);
    }

    public function test_disabled_chunker_returns_original_text(): void
    {
        $disabledChunker = new TextChunker(['enabled' => false]);
        $longText = str_repeat('word ', 5000);

        $chunks = $disabledChunker->chunk($longText, 'all-minilm');

        $this->assertCount(1, $chunks);
        $this->assertEquals($longText, $chunks[0]);
    }

    public function test_whitespace_only_text(): void
    {
        $chunks = $this->chunker->chunk('   ', 'nomic-embed-text');

        $this->assertCount(1, $chunks);
    }
}
