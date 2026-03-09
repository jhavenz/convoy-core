<?php

declare(strict_types=1);

namespace Convoy\Tests\Unit\Handler;

use Convoy\Handler\HandlerLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HandlerLoaderTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/convoy-handler-test-' . uniqid();
        mkdir($this->fixtureDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->fixtureDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->fixtureDir);
    }

    #[Test]
    public function loads_handler_group_from_file(): void
    {
        $content = <<<'PHP'
<?php
use Convoy\ExecutionScope;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Task\Task;

return HandlerGroup::of([
    'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'list')),
]);
PHP;

        file_put_contents($this->fixtureDir . '/routes.php', $content);

        $group = HandlerLoader::load($this->fixtureDir . '/routes.php');

        $this->assertContains('GET /users', $group->keys());
    }

    #[Test]
    public function throws_for_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler file not found');

        HandlerLoader::load('/nonexistent/file.php');
    }

    #[Test]
    public function throws_for_invalid_return_type(): void
    {
        $content = <<<'PHP'
<?php
return 'not a handler group';
PHP;

        file_put_contents($this->fixtureDir . '/invalid.php', $content);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler file must return RouteGroup, CommandGroup, HandlerGroup, or Closure');

        HandlerLoader::load($this->fixtureDir . '/invalid.php');
    }

    #[Test]
    public function loads_and_merges_directory(): void
    {
        $routes = <<<'PHP'
<?php
use Convoy\ExecutionScope;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Task\Task;

return HandlerGroup::of([
    'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'list')),
]);
PHP;

        $commands = <<<'PHP'
<?php
use Convoy\ExecutionScope;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Task\Task;

return HandlerGroup::of([
    'migrate' => Handler::command(Task::of(static fn(ExecutionScope $es) => 0)),
]);
PHP;

        file_put_contents($this->fixtureDir . '/routes.php', $routes);
        file_put_contents($this->fixtureDir . '/commands.php', $commands);

        $group = HandlerLoader::loadDirectory($this->fixtureDir);

        $this->assertContains('GET /users', $group->keys());
        $this->assertContains('migrate', $group->keys());
    }

    #[Test]
    public function throws_for_missing_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler directory not found');

        HandlerLoader::loadDirectory('/nonexistent/directory');
    }

    #[Test]
    public function glob_loads_matching_files(): void
    {
        $api = <<<'PHP'
<?php
use Convoy\ExecutionScope;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Task\Task;

return HandlerGroup::of([
    'GET /api/status' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'ok')),
]);
PHP;

        file_put_contents($this->fixtureDir . '/api.php', $api);
        file_put_contents($this->fixtureDir . '/readme.txt', 'not php');

        $group = HandlerLoader::glob($this->fixtureDir . '/*.php');

        $this->assertContains('GET /api/status', $group->keys());
    }

    #[Test]
    public function glob_returns_empty_for_no_matches(): void
    {
        $group = HandlerLoader::glob($this->fixtureDir . '/*.nonexistent');

        $this->assertSame([], $group->keys());
    }
}
