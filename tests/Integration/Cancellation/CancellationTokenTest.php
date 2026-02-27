<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Cancellation;

use Convoy\Concurrency\CancellationToken;
use Convoy\Exception\CancelledException;
use Convoy\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Async\await;
use function React\Async\delay;
use function React\Promise\Timer\timeout;

final class CancellationTokenTest extends AsyncTestCase
{
    #[Test]
    public function manual_cancel(): void
    {
        $token = CancellationToken::create();

        $this->assertFalse($token->isCancelled);

        $token->cancel();

        $this->assertTrue($token->isCancelled);
    }

    #[Test]
    public function cancel_triggers_callbacks(): void
    {
        $token = CancellationToken::create();
        $called = new \ArrayObject();

        $token->onCancel(function () use ($called) { $called[] = 'first'; });
        $token->onCancel(function () use ($called) { $called[] = 'second'; });

        $token->cancel();

        $this->assertEquals(['first', 'second'], $called->getArrayCopy());
    }

    #[Test]
    public function callback_on_already_cancelled_fires_immediately(): void
    {
        $token = CancellationToken::create();
        $token->cancel();

        $called = false;
        $token->onCancel(function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called, 'Callback should fire immediately on cancelled token');
    }

    #[Test]
    public function timeout_cancels_after_duration(): void
    {
        $this->runAsync(function () {
            $token = CancellationToken::timeout(0.05);
            $deferred = new Deferred();

            $token->onCancel(fn() => $deferred->resolve(true));

            $this->assertFalse($token->isCancelled);

            $wasCancelled = await(timeout($deferred->promise(), 0.1));

            $this->assertTrue($wasCancelled);
            $this->assertTrue($token->isCancelled);
        });
    }

    #[Test]
    public function composite_cancels_when_any_child_cancels(): void
    {
        $token1 = CancellationToken::create();
        $token2 = CancellationToken::create();

        $composite = CancellationToken::composite($token1, $token2);

        $this->assertFalse($composite->isCancelled);

        $token1->cancel();

        $this->assertTrue($composite->isCancelled);
    }

    #[Test]
    public function composite_already_cancelled_if_any_child_was(): void
    {
        $token1 = CancellationToken::create();
        $token2 = CancellationToken::create();

        $token1->cancel();

        $composite = CancellationToken::composite($token1, $token2);

        $this->assertTrue($composite->isCancelled);
    }

    #[Test]
    public function throw_if_cancelled_throws_on_cancelled(): void
    {
        $token = CancellationToken::create();
        $token->cancel();

        $this->expectException(CancelledException::class);

        $token->throwIfCancelled();
    }

    #[Test]
    public function throw_if_cancelled_does_nothing_when_not_cancelled(): void
    {
        $token = CancellationToken::create();

        $token->throwIfCancelled();

        $this->assertFalse($token->isCancelled);
    }

    #[Test]
    public function cancel_is_idempotent(): void
    {
        $token = CancellationToken::create();
        $callCount = 0;

        $token->onCancel(function () use (&$callCount) {
            $callCount++;
        });

        $token->cancel();
        $token->cancel();
        $token->cancel();

        $this->assertEquals(1, $callCount, 'Callback should only be called once');
    }

    #[Test]
    public function callbacks_cleared_after_cancel(): void
    {
        $token = CancellationToken::create();
        $called = new \ArrayObject();

        $token->onCancel(function () use ($called) { $called[] = 'before'; });
        $token->cancel();

        $this->assertEquals(['before'], $called->getArrayCopy());
    }

    #[Test]
    public function none_token_never_cancels(): void
    {
        $token = CancellationToken::none();

        $this->assertFalse($token->isCancelled);

        $token->throwIfCancelled();

        $this->assertFalse($token->isCancelled);
    }
}
