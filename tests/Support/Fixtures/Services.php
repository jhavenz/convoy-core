<?php

declare(strict_types=1);

namespace Convoy\Tests\Support\Fixtures;

interface LoggerInterface
{
    public function log(string $message): void;
}

final class Logger implements LoggerInterface
{
    public array $messages = [];
    public bool $started = false;
    public bool $shutdown = false;

    public function log(string $message): void
    {
        $this->messages[] = $message;
    }

    public function startup(): void
    {
        $this->started = true;
    }

    public function shutdown(): void
    {
        $this->shutdown = true;
    }
}

interface DatabaseInterface
{
    public function query(string $sql): array;
}

final class Database implements DatabaseInterface
{
    public bool $connected = false;
    public bool $disposed = false;
    public array $queries = [];

    public function __construct(
        public readonly Logger $logger,
    ) {
    }

    public function connect(): void
    {
        $this->connected = true;
        $this->logger->log('Database connected');
    }

    public function query(string $sql): array
    {
        $this->queries[] = $sql;
        return [];
    }

    public function dispose(): void
    {
        $this->disposed = true;
        $this->logger->log('Database disposed');
    }
}

interface UserRepositoryInterface
{
    public function find(int $id): ?array;
}

final class UserRepository implements UserRepositoryInterface
{
    public bool $disposed = false;

    public function __construct(
        public readonly Database $database,
        public readonly Logger $logger,
    ) {
    }

    public function find(int $id): ?array
    {
        $this->database->query("SELECT * FROM users WHERE id = $id");
        return ['id' => $id, 'name' => 'Test User'];
    }

    public function dispose(): void
    {
        $this->disposed = true;
        $this->logger->log('UserRepository disposed');
    }
}

final class ServiceA
{
    public function __construct(
        public readonly ServiceB $b,
    ) {
    }
}

final class ServiceB
{
    public function __construct(
        public readonly ServiceA $a,
    ) {
    }
}

final class ServiceC
{
    public function __construct(
        public readonly ServiceD $d,
    ) {
    }
}

final class ServiceD
{
    public function __construct(
        public readonly ServiceE $e,
    ) {
    }
}

final class ServiceE
{
    public function __construct(
        public readonly ServiceC $c,
    ) {
    }
}

final class IndependentService
{
    public int $createdAt;

    public function __construct()
    {
        $this->createdAt = hrtime(true);
    }
}

final class SingletonWithScopedDep
{
    public function __construct(
        public readonly ScopedService $scoped,
    ) {
    }
}

final class ScopedService
{
    public string $id;

    public function __construct()
    {
        $this->id = uniqid('scoped_', true);
    }
}

class CountingService
{
    public static int $instanceCount = 0;
    public int $instanceId;

    public function __construct()
    {
        self::$instanceCount++;
        $this->instanceId = self::$instanceCount;
    }

    public static function reset(): void
    {
        self::$instanceCount = 0;
    }
}

final class SlowService
{
    public bool $initialized = false;

    public function initialize(): void
    {
        usleep(10_000);
        $this->initialized = true;
    }
}

final class DisposalTracker
{
    /** @var list<string> */
    public static array $disposed = [];

    public static function reset(): void
    {
        self::$disposed = [];
    }

    public static function track(string $name): void
    {
        self::$disposed[] = $name;
    }
}

final class TrackedServiceA
{
    public function __construct()
    {
    }

    public function dispose(): void
    {
        DisposalTracker::track('A');
    }
}

final class TrackedServiceB
{
    public function __construct(
        public readonly TrackedServiceA $a,
    ) {
    }

    public function dispose(): void
    {
        DisposalTracker::track('B');
    }
}

final class TrackedServiceC
{
    public function __construct(
        public readonly TrackedServiceB $b,
    ) {
    }

    public function dispose(): void
    {
        DisposalTracker::track('C');
    }
}
