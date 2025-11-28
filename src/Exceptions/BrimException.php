<?php

namespace Brim\Exceptions;

use Exception;

class BrimException extends Exception
{
    public static function driverNotConfigured(string $driver): static
    {
        return new static("Embedding driver [{$driver}] is not configured.");
    }

    public static function storeNotConfigured(string $store): static
    {
        return new static("Vector store [{$store}] is not configured.");
    }

    public static function modelNotEmbeddable(string $class): static
    {
        return new static("Model [{$class}] does not implement the Embeddable interface.");
    }

    public static function invalidConfiguration(string $message): static
    {
        return new static("Invalid Brim configuration: {$message}");
    }
}
