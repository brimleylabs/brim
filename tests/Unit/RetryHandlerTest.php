<?php

namespace Brim\Tests\Unit;

use Brim\Exceptions\ConnectionException;
use Brim\Support\RetryHandler;
use Brim\Tests\TestCase;

/**
 * @group unit
 * @group retry
 */
class RetryHandlerTest extends TestCase
{
    private RetryHandler $retryHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->retryHandler = new RetryHandler([
            'max_attempts' => 3,
            'base_delay_ms' => 1, // Very short for tests
            'max_delay_ms' => 10,
            'multiplier' => 2.0,
        ]);
    }

    public function test_executes_callback_successfully_on_first_try(): void
    {
        $callCount = 0;
        $result = $this->retryHandler->execute(function () use (&$callCount) {
            $callCount++;
            return 'success';
        }, 'test.operation');

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $callCount);
    }

    public function test_retries_on_retryable_connection_exception(): void
    {
        $callCount = 0;
        $result = $this->retryHandler->execute(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new ConnectionException('Connection failed', 'localhost', 500);
            }
            return 'success';
        }, 'test.operation');

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $callCount);
    }

    public function test_throws_after_max_attempts(): void
    {
        $callCount = 0;

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection failed');

        $this->retryHandler->execute(function () use (&$callCount) {
            $callCount++;
            throw new ConnectionException('Connection failed', 'localhost', 500);
        }, 'test.operation');

        $this->assertEquals(3, $callCount);
    }

    public function test_does_not_retry_non_retryable_exception(): void
    {
        $callCount = 0;

        $this->expectException(ConnectionException::class);

        try {
            $this->retryHandler->execute(function () use (&$callCount) {
                $callCount++;
                throw new ConnectionException('Not found', 'localhost', 404);
            }, 'test.operation');
        } catch (ConnectionException $e) {
            $this->assertEquals(1, $callCount, 'Should not retry on 404');
            throw $e;
        }
    }

    public function test_wraps_generic_exceptions_in_connection_exception(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Operation [test.operation] failed: Generic error');

        $this->retryHandler->execute(function () {
            throw new \RuntimeException('Generic error');
        }, 'test.operation');
    }

    public function test_retries_on_rate_limit(): void
    {
        $callCount = 0;
        $result = $this->retryHandler->execute(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new ConnectionException('Rate limited', 'api.openai.com', 429);
            }
            return 'success';
        }, 'test.operation');

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $callCount);
    }

    public function test_retries_on_server_error(): void
    {
        $callCount = 0;
        $result = $this->retryHandler->execute(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new ConnectionException('Server error', 'localhost', 503);
            }
            return 'success';
        }, 'test.operation');

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $callCount);
    }

    public function test_get_max_attempts(): void
    {
        $this->assertEquals(3, $this->retryHandler->getMaxAttempts());

        $customHandler = new RetryHandler(['max_attempts' => 5]);
        $this->assertEquals(5, $customHandler->getMaxAttempts());
    }

    public function test_uses_default_config_when_null(): void
    {
        // When config() returns null, defaults should be used
        $handler = new RetryHandler([]);

        $this->assertEquals(3, $handler->getMaxAttempts());
    }
}
