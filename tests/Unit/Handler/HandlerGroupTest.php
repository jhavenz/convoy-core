<?php

declare(strict_types=1);

namespace Convoy\Tests\Unit\Handler;

use Convoy\Handler\CommandConfig;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Handler\RouteConfig;
use Convoy\ExecutionScope;
use Convoy\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final class HandlerGroupTest extends TestCase
{
    #[Test]
    public function creates_from_array_with_route_handlers(): void
    {
        $group = HandlerGroup::of([
            'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'list')),
            'GET /users/{id}' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'show')),
        ]);

        $keys = $group->keys();

        $this->assertCount(2, $keys);
        $this->assertContains('GET /users', $keys);
        $this->assertContains('GET /users/{id}', $keys);
    }

    #[Test]
    public function creates_from_array_with_command_handlers(): void
    {
        $group = HandlerGroup::of([
            'migrate' => Handler::command(Task::of(static fn(ExecutionScope $es) => 0), 'Run migrations'),
        ]);

        $handler = $group->get('migrate');

        $this->assertNotNull($handler);
        $this->assertInstanceOf(CommandConfig::class, $handler->config);
        $this->assertSame('Run migrations', $handler->config->description);
    }

    #[Test]
    public function creates_from_dispatchable_directly(): void
    {
        $group = HandlerGroup::of([
            'task' => Task::of(static fn(ExecutionScope $es) => 'result'),
        ]);

        $this->assertNotNull($group->get('task'));
    }

    #[Test]
    public function fluent_route_adds_handler(): void
    {
        $group = HandlerGroup::create()
            ->route('/users', Task::of(static fn(ExecutionScope $es) => 'list'))
            ->route('/users/{id}', Task::of(static fn(ExecutionScope $es) => 'show'));

        $this->assertCount(2, $group->keys());
    }

    #[Test]
    public function fluent_command_adds_handler(): void
    {
        $group = HandlerGroup::create()
            ->command('migrate', Task::of(static fn(ExecutionScope $es) => 0), 'Run migrations');

        $handler = $group->get('migrate');

        $this->assertNotNull($handler);
        $this->assertInstanceOf(CommandConfig::class, $handler->config);
    }

    #[Test]
    public function merge_combines_groups(): void
    {
        $group1 = HandlerGroup::of([
            'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'list')),
        ]);

        $group2 = HandlerGroup::of([
            'GET /posts' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'posts')),
        ]);

        $merged = $group1->merge($group2);

        $this->assertCount(2, $merged->keys());
        $this->assertContains('GET /users', $merged->keys());
        $this->assertContains('GET /posts', $merged->keys());
    }

    #[Test]
    public function merge_later_overrides_earlier(): void
    {
        $task1 = Task::of(static fn(ExecutionScope $es) => 'first');
        $task2 = Task::of(static fn(ExecutionScope $es) => 'second');

        $group1 = HandlerGroup::of(['GET /users' => Handler::route($task1)]);
        $group2 = HandlerGroup::of(['GET /users' => Handler::route($task2)]);

        $merged = $group1->merge($group2);
        $handler = $merged->get('GET /users');

        $this->assertSame($task2, $handler->task);
    }

    #[Test]
    public function mount_prefixes_routes(): void
    {
        $group = HandlerGroup::of([
            'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'list')),
            'GET /users/{id}' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'show')),
        ]);

        $mounted = HandlerGroup::create()->mount('/api/v1', $group);

        $this->assertContains('GET /api/v1/users', $mounted->keys());
        $this->assertContains('GET /api/v1/users/{id}', $mounted->keys());
    }

    #[Test]
    public function mount_preserves_commands(): void
    {
        $group = HandlerGroup::of([
            'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'list')),
            'migrate' => Handler::command(Task::of(static fn(ExecutionScope $es) => 0)),
        ]);

        $mounted = HandlerGroup::create()->mount('/api', $group);

        $this->assertContains('GET /api/users', $mounted->keys());
        $this->assertContains('migrate', $mounted->keys());
    }

    #[Test]
    public function routes_returns_only_route_handlers(): void
    {
        $group = HandlerGroup::of([
            'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'list')),
            'migrate' => Handler::command(Task::of(static fn(ExecutionScope $es) => 0)),
        ]);

        $routes = $group->routes();

        $this->assertCount(1, $routes);
        $this->assertArrayHasKey('GET /users', $routes);
    }

    #[Test]
    public function commands_returns_only_command_handlers(): void
    {
        $group = HandlerGroup::of([
            'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'list')),
            'migrate' => Handler::command(Task::of(static fn(ExecutionScope $es) => 0)),
        ]);

        $commands = $group->commands();

        $this->assertCount(1, $commands);
        $this->assertArrayHasKey('migrate', $commands);
    }

    #[Test]
    public function compiles_route_pattern_from_key(): void
    {
        $group = HandlerGroup::of([
            'GET /users/{id}' => Handler::route(Task::of(static fn(ExecutionScope $es) => 'show')),
        ]);

        $handler = $group->get('GET /users/{id}');

        $this->assertInstanceOf(RouteConfig::class, $handler->config);
        $this->assertSame(['id'], $handler->config->paramNames);
    }
}
