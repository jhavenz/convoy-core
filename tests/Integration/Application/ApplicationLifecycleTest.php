<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Application;

use Convoy\Application;
use Convoy\Concurrency\CancellationToken;
use Convoy\Tests\Support\AsyncTestCase;
use Convoy\Tests\Support\Fixtures\Database;
use Convoy\Tests\Support\Fixtures\Logger;
use Convoy\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;

final class ApplicationLifecycleTest extends AsyncTestCase
{
    #[Test]
    public function startup_hooks_called_on_startup(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class)
            ->asEager(Logger::class)
            ->withLifecycle(Logger::class, 'startup', fn(Logger $l) => $l->startup());

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();
        $logger = $scope->service(Logger::class);

        $this->assertFalse($logger->started, 'Not started before startup()');

        $app->startup();

        $this->assertTrue($logger->started, 'Started after startup()');
    }

    #[Test]
    public function shutdown_hooks_called_on_shutdown(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class)
            ->asEager(Logger::class)
            ->withLifecycle(Logger::class, 'startup', fn(Logger $l) => $l->startup())
            ->withLifecycle(Logger::class, 'shutdown', fn(Logger $l) => $l->shutdown());

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();

        $scope = $app->createScope();
        $logger = $scope->service(Logger::class);

        $this->assertFalse($logger->shutdown, 'Not shutdown before shutdown()');

        $app->shutdown();

        $this->assertTrue($logger->shutdown, 'Shutdown after shutdown()');
    }

    #[Test]
    public function startup_is_idempotent(): void
    {
        $startupCount = 0;

        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class)
            ->asEager(Logger::class)
            ->withLifecycle(Logger::class, 'startup', function () use (&$startupCount) {
                $startupCount++;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();
        $app->startup();
        $app->startup();

        $this->assertEquals(1, $startupCount, 'Startup should only run once');
    }

    #[Test]
    public function shutdown_is_idempotent(): void
    {
        $shutdownCount = 0;

        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class)
            ->asEager(Logger::class)
            ->withLifecycle(Logger::class, 'shutdown', function () use (&$shutdownCount) {
                $shutdownCount++;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();
        $app->shutdown();
        $app->shutdown();
        $app->shutdown();

        $this->assertEquals(1, $shutdownCount, 'Shutdown should only run once');
    }

    #[Test]
    public function scope_respects_cancellation_token(): void
    {
        $app = Application::starting()->compile();

        $token = CancellationToken::create();
        $scope = $app->createScope($token);

        $this->assertFalse($scope->isCancelled);

        $token->cancel();

        $this->assertTrue($scope->isCancelled);
    }

    #[Test]
    public function multiple_providers_merged(): void
    {
        $bundle1 = TestServiceBundle::create()
            ->singleton(Logger::class);

        $bundle2 = TestServiceBundle::create()
            ->singleton(Database::class, fn(Logger $logger) => new Database($logger))
            ->withDependencies(Database::class, Logger::class);

        $app = Application::starting()
            ->providers($bundle1, $bundle2)
            ->compile();

        $scope = $app->createScope();

        $logger = $scope->service(Logger::class);
        $database = $scope->service(Database::class);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertInstanceOf(Database::class, $database);
        $this->assertSame($logger, $database->logger);
    }
}
