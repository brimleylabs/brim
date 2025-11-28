<?php

namespace Brim\Drivers;

use Brim\Contracts\EmbeddingDriver;
use Brim\Exceptions\ConnectionException;
use Brim\Exceptions\EmbeddingException;
use Brim\Support\RetryHandler;
use Brim\Support\TextChunker;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class OpenAIDriver implements EmbeddingDriver
{
    protected Client $client;
    protected string $apiKey;
    protected string $model;
    protected int $timeout;
    protected RetryHandler $retry;
    protected TextChunker $chunker;

    protected const API_URL = 'https://api.openai.com/v1/embeddings';

    protected const MODEL_SPECS = [
        'text-embedding-3-small' => ['dimensions' => 1536, 'token_limit' => 8191],
        'text-embedding-3-large' => ['dimensions' => 3072, 'token_limit' => 8191],
        'text-embedding-ada-002' => ['dimensions' => 1536, 'token_limit' => 8191],
    ];

    public function __construct(?array $config = null, ?RetryHandler $retry = null, ?TextChunker $chunker = null)
    {
        $config = $config ?? config('brim.embeddings.openai', []);

        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'text-embedding-3-small';
        $this->timeout = $config['timeout'] ?? 30;

        $this->client = new Client([
            'timeout' => $this->timeout,
        ]);

        $this->retry = $retry ?? new RetryHandler();
        $this->chunker = $chunker ?? new TextChunker();
    }

    /**
     * @inheritDoc
     */
    public function embed(string $text): array
    {
        if (empty(trim($text))) {
            throw EmbeddingException::emptyInput();
        }

        return $this->retry->execute(function () use ($text) {
            return $this->doEmbed($text);
        }, 'openai.embed');
    }

    /**
     * @inheritDoc
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        // OpenAI supports batch embedding in a single request
        return $this->retry->execute(function () use ($texts) {
            return $this->doEmbedBatch($texts);
        }, 'openai.embedBatch');
    }

    /**
     * Perform the actual embedding request.
     *
     * @param string $text
     * @return array<float>
     * @throws ConnectionException
     * @throws EmbeddingException
     */
    protected function doEmbed(string $text): array
    {
        $embeddings = $this->doEmbedBatch([$text]);
        return $embeddings[0];
    }

    /**
     * Perform batch embedding request.
     *
     * @param array<string> $texts
     * @return array<array<float>>
     * @throws ConnectionException
     * @throws EmbeddingException
     */
    protected function doEmbedBatch(array $texts): array
    {
        if (empty($this->apiKey)) {
            throw new EmbeddingException('OpenAI API key is not configured.');
        }

        try {
            $response = $this->client->post(self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'input' => $texts,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['data']) || !is_array($data['data'])) {
                throw EmbeddingException::invalidResponse('openai', 'Missing data in response');
            }

            // Sort by index to ensure correct order
            usort($data['data'], fn($a, $b) => $a['index'] <=> $b['index']);

            $embeddings = [];
            $expectedDimensions = $this->dimensions();

            foreach ($data['data'] as $item) {
                if (!isset($item['embedding']) || !is_array($item['embedding'])) {
                    throw EmbeddingException::invalidResponse('openai', 'Missing embedding in response item');
                }

                if (count($item['embedding']) !== $expectedDimensions) {
                    throw EmbeddingException::dimensionMismatch($expectedDimensions, count($item['embedding']));
                }

                $embeddings[] = $item['embedding'];
            }

            return $embeddings;
        } catch (ConnectException $e) {
            throw ConnectionException::timeout('api.openai.com', $this->timeout);
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
            $message = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();

            if ($statusCode === 429) {
                $retryAfter = $e->hasResponse()
                    ? $e->getResponse()->getHeader('Retry-After')[0] ?? null
                    : null;
                throw ConnectionException::rateLimited('openai', $retryAfter ? (int) $retryAfter : null);
            }

            if ($statusCode === 401) {
                throw new EmbeddingException('Invalid OpenAI API key.');
            }

            throw ConnectionException::openAiFailed($statusCode ?? 0, $message);
        }
    }

    /**
     * @inheritDoc
     */
    public function dimensions(): int
    {
        return self::MODEL_SPECS[$this->model]['dimensions'] ?? 1536;
    }

    /**
     * @inheritDoc
     */
    public function tokenLimit(): int
    {
        return self::MODEL_SPECS[$this->model]['token_limit'] ?? 8191;
    }

    /**
     * @inheritDoc
     */
    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * @inheritDoc
     */
    public function healthCheck(): array
    {
        if (empty($this->apiKey)) {
            return [
                'healthy' => false,
                'message' => 'OpenAI API key is not configured',
                'details' => [
                    'model' => $this->model,
                    'api_key_set' => false,
                ],
            ];
        }

        try {
            // Make a minimal embedding request to test the API
            $this->doEmbed('test');

            return [
                'healthy' => true,
                'message' => "OpenAI API connected, model [{$this->model}] available",
                'details' => [
                    'model' => $this->model,
                    'api_key_set' => true,
                ],
            ];
        } catch (EmbeddingException $e) {
            return [
                'healthy' => false,
                'message' => $e->getMessage(),
                'details' => [
                    'model' => $this->model,
                    'api_key_set' => true,
                    'error' => $e->getMessage(),
                ],
            ];
        } catch (ConnectionException $e) {
            return [
                'healthy' => false,
                'message' => $e->getMessage(),
                'details' => [
                    'model' => $this->model,
                    'api_key_set' => true,
                    'error' => $e->getMessage(),
                    'status_code' => $e->getStatusCode(),
                ],
            ];
        }
    }
}
