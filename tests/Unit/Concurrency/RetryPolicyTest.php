<?php

declare(strict_types=1);

namespace Convoy\Tests\Unit\Concurrency;

use Convoy\Concurrency\RetryPolicy;
use Convoy\Exception\CancelledException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function exponential_backoff_doubles_delay(): void
    {
        $policy = RetryPolicy::exponential(5, baseDelayMs: 100.0, maxDelayMs: 10000.0);

        $delays = [];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $delay = $policy->calculateDelay($attempt);
            $delays[] = $delay;
        }

        $this->assertGreaterThanOrEqual(100, $delays[0]);
        $this->assertLessThanOrEqual(110, $delays[0]);

        $this->assertGreaterThanOrEqual(200, $delays[1]);
        $this->assertLessThanOrEqual(220, $delays[1]);

        $this->assertGreaterThanOrEqual(400, $delays[2]);
        $this->assertLessThanOrEqual(440, $delays[2]);

        $this->assertGreaterThanOrEqual(800, $delays[3]);
        $this->assertLessThanOrEqual(880, $delays[3]);

        $this->assertGreaterThanOrEqual(1600, $delays[4]);
        $this->assertLessThanOrEqual(1760, $delays[4]);
    }

    #[Test]
    public function linear_backoff_increases_linearly(): void
    {
        $policy = RetryPolicy::linear(5, baseDelayMs: 100.0, maxDelayMs: 10000.0);

        $delay1 = $policy->calculateDelay(1);
        $delay2 = $policy->calculateDelay(2);
        $delay3 = $policy->calculateDelay(3);

        $this->assertGreaterThanOrEqual(100, $delay1);
        $this->assertGreaterThanOrEqual(200, $delay2);
        $this->assertGreaterThanOrEqual(300, $delay3);
    }

    #[Test]
    public function fixed_backoff_constant_delay(): void
    {
        $policy = RetryPolicy::fixed(5, delayMs: 500.0);

        $delay1 = $policy->calculateDelay(1);
        $delay2 = $policy->calculateDelay(2);
        $delay3 = $policy->calculateDelay(3);

        $this->assertGreaterThanOrEqual(500, $delay1);
        $this->assertLessThanOrEqual(550, $delay1);

        $this->assertGreaterThanOrEqual(500, $delay2);
        $this->assertLessThanOrEqual(550, $delay2);

        $this->assertGreaterThanOrEqual(500, $delay3);
        $this->assertLessThanOrEqual(550, $delay3);
    }

    #[Test]
    public function max_delay_caps_exponential_growth(): void
    {
        $policy = RetryPolicy::exponential(10, baseDelayMs: 100.0, maxDelayMs: 500.0);

        $delay = $policy->calculateDelay(10);

        $this->assertLessThanOrEqual(500, $delay);
    }

    #[Test]
    public function jitter_adds_to_base_delay(): void
    {
        $policy = RetryPolicy::fixed(5, delayMs: 1000.0);

        $delay = $policy->calculateDelay(1);

        $this->assertGreaterThanOrEqual(1000, $delay, 'Delay should be at least base');
        $this->assertLessThanOrEqual(1100, $delay, 'Delay should be at most base + 10% jitter');
    }

    #[Test]
    public function should_retry_returns_true_by_default(): void
    {
        $policy = RetryPolicy::exponential(3);

        $this->assertTrue($policy->shouldRetry(new \RuntimeException()));
        $this->assertTrue($policy->shouldRetry(new \InvalidArgumentException()));
        $this->assertTrue($policy->shouldRetry(new \LogicException()));
    }

    #[Test]
    public function should_retry_returns_false_for_cancelled(): void
    {
        $policy = RetryPolicy::exponential(3);

        $this->assertFalse($policy->shouldRetry(new CancelledException()));
    }

    #[Test]
    public function retrying_on_filters_exceptions(): void
    {
        $policy = RetryPolicy::exponential(3)
            ->retryingOn(\RuntimeException::class);

        $this->assertTrue($policy->shouldRetry(new \RuntimeException()));
        $this->assertFalse($policy->shouldRetry(new \InvalidArgumentException()));
    }

    #[Test]
    public function retrying_on_matches_subclasses(): void
    {
        $policy = RetryPolicy::exponential(3)
            ->retryingOn(\RuntimeException::class);

        $this->assertTrue($policy->shouldRetry(new \UnexpectedValueException()));
    }

    #[Test]
    public function attempt_zero_returns_zero_delay(): void
    {
        $policy = RetryPolicy::exponential(3, baseDelayMs: 100.0);

        $this->assertEquals(0.0, $policy->calculateDelay(0));
    }

    #[Test]
    public function invalid_attempts_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempts must be at least 1');

        RetryPolicy::exponential(0);
    }

    #[Test]
    public function invalid_backoff_strategy_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid backoff strategy');

        new RetryPolicy(3, 'invalid', 100.0, 1000.0);
    }
}
