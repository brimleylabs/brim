<?php

namespace Brim\Stores;

use Brim\Contracts\VectorStore;
use Brim\Exceptions\BrimException;
use Illuminate\Support\Manager;

class VectorStoreManager extends Manager
{
    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('brim.vector_store.driver', 'pgvector');
    }

    /**
     * Create the pgvector driver instance.
     *
     * @return VectorStore
     */
    protected function createPgvectorDriver(): VectorStore
    {
        return new PgVectorStore(
            $this->config->get('brim.vector_store', [])
        );
    }

    /**
     * Get a driver instance.
     *
     * @param string|null $driver
     * @return VectorStore
     * @throws BrimException
     */
    public function driver($driver = null): VectorStore
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
     * @return VectorStore
     * @throws BrimException
     */
    protected function createDriver($driver): VectorStore
    {
        $method = 'create' . ucfirst($driver) . 'Driver';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        throw BrimException::storeNotConfigured($driver);
    }

    /**
     * Get all available driver names.
     *
     * @return array<string>
     */
    public function availableDrivers(): array
    {
        return ['pgvector'];
    }
}
