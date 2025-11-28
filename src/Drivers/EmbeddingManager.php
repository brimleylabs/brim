<?php

namespace Brim\Drivers;

use Brim\Contracts\EmbeddingDriver;
use Brim\Exceptions\BrimException;
use Illuminate\Support\Manager;

class EmbeddingManager extends Manager
{
    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('brim.embeddings.driver', 'ollama');
    }

    /**
     * Create the Ollama driver instance.
     *
     * @return EmbeddingDriver
     */
    protected function createOllamaDriver(): EmbeddingDriver
    {
        return new OllamaDriver(
            $this->config->get('brim.embeddings.ollama', [])
        );
    }

    /**
     * Create the OpenAI driver instance.
     *
     * @return EmbeddingDriver
     */
    protected function createOpenaiDriver(): EmbeddingDriver
    {
        return new OpenAIDriver(
            $this->config->get('brim.embeddings.openai', [])
        );
    }

    /**
     * Get a driver instance.
     *
     * @param string|null $driver
     * @return EmbeddingDriver
     * @throws BrimException
     */
    public function driver($driver = null): EmbeddingDriver
    {
        $driver = $driver ?: $this->getDefaultDriver();

        if (!isset($this->drivers[$driver])) {
            $this->drivers[$driver] = $this->createDriver($driver);
        }

        return $this->drivers[$driver];
    }

    /**
     * Create a new driver instance.
     *
     * @param string $driver
     * @return EmbeddingDriver
     * @throws BrimException
     */
    protected function createDriver($driver): EmbeddingDriver
    {
        $method = 'create' . ucfirst($driver) . 'Driver';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        throw BrimException::driverNotConfigured($driver);
    }

    /**
     * Get all available driver names.
     *
     * @return array<string>
     */
    public function availableDrivers(): array
    {
        return ['ollama', 'openai'];
    }
}
