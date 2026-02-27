<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Scope;

use Convoy\Application;
use Convoy\Tests\Support\AsyncTestCase;
use Convoy\Tests\Support\Fixtures\CountingService;
use Convoy\Tests\Support\Fixtures\Database;
use Convoy\Tests\Support\Fixtures\DisposalTracker;
use Convoy\Tests\Support\Fixtures\Logger;
use Convoy\Tests\Support\Fixtures\ScopedService;
use Convoy\Tests\Support\Fixtures\TrackedServiceA;
use Convoy\Tests\Support\Fixtures\TrackedServiceB;
use Convoy\Tests\Support\Fixtures\TrackedServiceC;
use Convoy\Tests\Support\Fixtures\UserRepository;
use Convoy\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;

final class ScopeServiceResolutionTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CountingService::reset();
        DisposalTracker::reset();
    }

    #[Test]
    public function scoped_service_unique_per_scope(): void
    {
        $bundle = TestServiceBundle::create()
            ->scoped(ScopedService::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope1 = $app->createScope();
        $scope2 = $app->createScope();

        $svc1a = $scope1->service(ScopedService::class);
        $svc1b = $scope1->service(ScopedService::class);
        $svc2 = $scope2->service(ScopedService::class);

        $this->assertSame($svc1a, $svc1b, 'Same scope returns same instance');
        $this->assertNotSame($svc1a, $svc2, 'Different scopes return different instances');
        $this->assertNotEquals($svc1a->id, $svc2->id);
    }

    #[Test]
    public function singleton_shared_across_scopes(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope1 = $app->createScope();
        $scope2 = $app->createScope();

        $logger1 = $scope1->service(Logger::class);
        $logger2 = $scope2->service(Logger::class);

        $this->assertSame($logger1, $logger2);
    }

    #[Test]
    public function disposal_order_reverses_creation(): void
    {
        $bundle = TestServiceBundle::create()
            ->scoped(TrackedServiceA::class, fn($deferred) => new TrackedServiceA())
            ->scoped(TrackedServiceB::class, fn($deferred, TrackedServiceA $a) => new TrackedServiceB($a))
            ->withDependencies(TrackedServiceB::class, TrackedServiceA::class)
            ->scoped(TrackedServiceC::class, fn($deferred, TrackedServiceA $a, TrackedServiceB $b) => new TrackedServiceC($b))
            ->withDependencies(TrackedServiceC::class, TrackedServiceB::class)
            ->withLifecycle(TrackedServiceA::class, 'dispose', fn($s) => $s->dispose())
            ->withLifecycle(TrackedServiceB::class, 'dispose', fn($s) => $s->dispose())
            ->withLifecycle(TrackedServiceC::class, 'dispose', fn($s) => $s->dispose());

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();

        $scope->service(TrackedServiceC::class);

        $scope->dispose();

        $this->assertEquals(['C', 'B', 'A'], DisposalTracker::$disposed);
    }

    #[Test]
    public function lazy_service_not_initialized_until_accessed(): void
    {
        $initLog = new \ArrayObject();

        $bundle = TestServiceBundle::create()
            ->scoped(CountingService::class, function ($deferred) use ($initLog) {
                $initLog[] = 'created';
                return new CountingService();
            })
            ->asLazy(CountingService::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();
        $svc = $scope->service(CountingService::class);

        $this->assertEmpty($initLog->getArrayCopy(), 'Lazy service should not be initialized yet');

        $_ = $svc->instanceId;

        $this->assertCount(1, $initLog, 'Lazy service should be initialized on first access');
    }

    #[Test]
    public function eager_service_initialized_immediately(): void
    {
        $initLog = new \ArrayObject();

        $bundle = TestServiceBundle::create()
            ->scoped(CountingService::class, function ($deferred) use ($initLog) {
                $initLog[] = 'created';
                return new CountingService();
            })
            ->asEager(CountingService::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();
        $scope->service(CountingService::class);

        $this->assertCount(1, $initLog, 'Eager service should be initialized immediately');
    }

    #[Test]
    public function scoped_with_singleton_dependency_resolves_correctly(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class)
            ->scoped(Database::class, fn($deferred, Logger $logger) => new Database($logger))
            ->withDependencies(Database::class, Logger::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope1 = $app->createScope();
        $scope2 = $app->createScope();

        $db1 = $scope1->service(Database::class);
        $db2 = $scope2->service(Database::class);

        $this->assertNotSame($db1, $db2, 'Scoped databases are different');
        $this->assertSame($db1->logger, $db2->logger, 'Both use same singleton logger');
    }

    #[Test]
    public function dependency_chain_resolves_in_order(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class)
            ->singleton(Database::class, fn(Logger $logger) => new Database($logger))
            ->withDependencies(Database::class, Logger::class)
            ->singleton(UserRepository::class, fn(Logger $logger, Database $db) => new UserRepository($db, $logger))
            ->withDependencies(UserRepository::class, Database::class, Logger::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();
        $repo = $scope->service(UserRepository::class);

        $this->assertInstanceOf(UserRepository::class, $repo);
        $this->assertInstanceOf(Database::class, $repo->database);
        $this->assertInstanceOf(Logger::class, $repo->logger);
        $this->assertSame($repo->database->logger, $repo->logger);
    }
}
