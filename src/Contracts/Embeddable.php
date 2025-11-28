<?php

namespace Brim\Contracts;

interface Embeddable
{
    /**
     * Get the text content to be embedded.
     *
     * @return string
     */
    public function toEmbeddableText(): string;

    /**
     * Get the namespace for this model's embeddings.
     *
     * @return string|null
     */
    public function getEmbeddingNamespace(): ?string;
}
