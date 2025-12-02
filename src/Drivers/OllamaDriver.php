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

class OllamaDriver implements EmbeddingDriver
{
    protected Client $client;
    protected string $host;
    protected string $model;
    protected int $timeout;
    protected RetryHandler $retry;
    protected TextChunker $chunker;

    protected const MODEL_SPECS = [
        'nomic-embed-text' => ['dimensions' => 768, 'token_limit' => 8192],
        'all-minilm' => ['dimensions' => 384, 'token_limit' => 256],
        'mxbai-embed-large' => ['dimensions' => 1024, 'token_limit' => 512],
    ];

    public function __construct(?array $config = null, ?RetryHandler $retry = null, ?TextChunker $chunker = null)
    {
        $config = $config ?? config('brim.embeddings.ollama', []);

        $this->host = rtrim($config['host'] ?? 'http://localhost:11434', '/');
        $this->model = $config['model'] ?? 'nomic-embed-text';
        $this->timeout = $config['timeout'] ?? 30;

        $this->client = new Client([
            'base_uri' => $this->host,
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
        }, 'ollama.embed');
    }

    /**
     * @inheritDoc
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $embeddings = [];

        foreach ($texts as $text) {
            $embeddings[] = $this->embed($text);
        }

        return $embeddings;
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
        try {
            $response = $this->client->post('/api/embed', [
                'json' => [
                    'model' => $this->model,
                    'input' => $text,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['embeddings'][0]) || !is_array($data['embeddings'][0])) {
                throw EmbeddingException::invalidResponse('ollama', 'Missing embeddings in response');
            }

            $embedding = $data['embeddings'][0];

            // Validate dimensions
            $expected = $this->dimensions();
            if (count($embedding) !== $expected) {
                throw EmbeddingException::dimensionMismatch($expected, count($embedding));
            }

            return $embedding;
        } catch (ConnectException $e) {
            throw ConnectionException::ollamaUnavailable($this->host, $e);
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
            $message = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();

            if ($statusCode === 404) {
                throw EmbeddingException::modelNotAvailable($this->model, 'ollama');
            }

            throw new ConnectionException(
                "Ollama request failed: {$message}",
                $this->host,
                $statusCode,
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function dimensions(): int
    {
        return self::MODEL_SPECS[$this->model]['dimensions'] ?? 768;
    }

    /**
     * @inheritDoc
     */
    public function tokenLimit(): int
    {
        return self::MODEL_SPECS[$this->model]['token_limit'] ?? 8192;
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
        try {
            $response = $this->client->get('/api/tags');
            $data = json_decode($response->getBody()->getContents(), true);

            $models = array_column($data['models'] ?? [], 'name');
            $modelInstalled = in_array($this->model, $models) ||
                in_array($this->model . ':latest', $models);

            return [
                'healthy' => true,
                'message' => $modelInstalled
                    ? "Ollama running, model [{$this->model}] available"
                    : "Ollama running, but model [{$this->model}] not installed",
                'details' => [
                    'host' => $this->host,
                    'model' => $this->model,
                    'model_installed' => $modelInstalled,
                    'available_models' => $models,
                ],
            ];
        } catch (ConnectException $e) {
            return [
                'healthy' => false,
                'message' => "Cannot connect to Ollama at [{$this->host}]",
                'details' => [
                    'host' => $this->host,
                    'error' => $e->getMessage(),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'message' => "Ollama health check failed: {$e->getMessage()}",
                'details' => [
                    'host' => $this->host,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Get the Ollama host URL.
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }
}
