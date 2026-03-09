<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Handler;

use Convoy\Application;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\ExecutionScope;
use Convoy\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final class HandlerDispatchTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    #[Test]
    public function dispatches_route_by_request_attribute(): void
    {
        $group = HandlerGroup::of([
            'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => ['users' => []])),
            'GET /posts' => Handler::route(Task::of(static fn(ExecutionScope $es) => ['posts' => []])),
        ]);

        $request = $this->createRequest('GET', '/users');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame(['users' => []], $result);
    }

    #[Test]
    public function extracts_route_params_to_attributes(): void
    {
        $group = HandlerGroup::of([
            'GET /users/{id}' => Handler::route(Task::of(static fn(ExecutionScope $es): array => [
                'id' => $es->attribute('route.id'),
                'params' => $es->attribute('route.params'),
            ])),
        ]);

        $request = $this->createRequest('GET', '/users/42');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame('42', $result['id']);
        $this->assertSame(['id' => '42'], $result['params']);
    }

    #[Test]
    public function dispatches_command_by_command_attribute(): void
    {
        $group = HandlerGroup::of([
            'migrate' => Handler::command(Task::of(static fn(ExecutionScope $es) => 0)),
            'seed' => Handler::command(Task::of(static fn(ExecutionScope $es) => 1)),
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('command', 'migrate');

        $result = $scope->execute($group);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function dispatches_by_handler_key(): void
    {
        $group = HandlerGroup::of([
            'task-a' => Handler::of(Task::of(static fn(ExecutionScope $es) => 'a')),
            'task-b' => Handler::of(Task::of(static fn(ExecutionScope $es) => 'b')),
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('handler.key', 'task-b');

        $result = $scope->execute($group);

        $this->assertSame('b', $result);
    }

    #[Test]
    public function throws_when_no_route_matches(): void
    {
        $group = HandlerGroup::of([
            'GET /users' => Handler::route(Task::of(static fn(ExecutionScope $es) => [])),
        ]);

        $request = $this->createRequest('GET', '/posts');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No route matches GET /posts');

        $scope->execute($group);
    }

    #[Test]
    public function throws_when_command_not_found(): void
    {
        $group = HandlerGroup::of([
            'migrate' => Handler::command(Task::of(static fn(ExecutionScope $es) => 0)),
        ]);

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('command', 'unknown');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command not found: unknown');

        $scope->execute($group);
    }

    #[Test]
    public function applies_group_middleware(): void
    {
        $calls = [];

        $middleware = Task::of(static function (ExecutionScope $es) use (&$calls): mixed {
            $calls[] = 'middleware:before';
            $next = $es->attribute('handler.next');
            $result = $es->execute($next);
            $calls[] = 'middleware:after';
            return $result;
        });

        $group = HandlerGroup::of([
            'GET /test' => Handler::route(Task::of(static function (ExecutionScope $es) use (&$calls): string {
                $calls[] = 'handler';
                return 'done';
            })),
        ])->wrap($middleware);

        $request = $this->createRequest('GET', '/test');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame('done', $result);
        $this->assertSame(['middleware:before', 'handler', 'middleware:after'], $calls);
    }

    #[Test]
    public function matches_multiple_methods(): void
    {
        $group = HandlerGroup::create()
            ->route('/resource', Task::of(static fn(ExecutionScope $es) => 'ok'), ['GET', 'POST']);

        foreach (['GET', 'POST'] as $method) {
            $request = $this->createRequest($method, '/resource');
            $scope = $this->app->createScope();
            $scope = $scope->withAttribute('request', $request);

            $result = $scope->execute($group);

            $this->assertSame('ok', $result);
        }
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }
}
