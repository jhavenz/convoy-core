<?php

declare(strict_types=1);

namespace Convoy\Runtime;

use Convoy\AppHost;
use Convoy\Concurrency\CancellationToken;
use Convoy\Trace\TraceType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Symfony\Component\Runtime\RunnerInterface;

final class ConvoyHttpRunner implements RunnerInterface
{
    private ?HttpServer $server = null;
    private ?SocketServer $socket = null;
    private bool $running = false;

    /** @var callable(ServerRequestInterface, \Convoy\Scope): mixed */
    private $handler;

    public function __construct(
        private readonly AppHost $app,
        private readonly string $host,
        private readonly int $port,
        private readonly float $requestTimeout = 30.0,
    ) {
    }

    public function withHandler(callable $handler): self
    {
        $clone = clone $this;
        $clone->handler = $handler;
        return $clone;
    }

    public function run(): int
    {
        if (!isset($this->handler)) {
            throw new \LogicException('No request handler configured. Call withHandler() before run().');
        }

        $this->app->startup();

        $this->server = new HttpServer(function (ServerRequestInterface $request): ResponseInterface {
            return $this->handleRequest($request);
        });

        $uri = "{$this->host}:{$this->port}";
        $this->socket = new SocketServer($uri);
        $this->server->listen($this->socket);

        $this->running = true;
        $this->app->trace()->log(TraceType::LifecycleStartup, 'ready');

        echo "Convoy HTTP server listening on http://$uri\n";

        $this->setupSignalHandlers();

        Loop::run();

        return 0;
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $token = CancellationToken::timeout($this->requestTimeout);
        $scope = $this->app->createScope($token);
        $trace = $scope->trace();
        $trace->reset();

        try {
            $handler = $this->handler;
            $response = $handler($request, $scope);

            if (!$response instanceof ResponseInterface) {
                $response = $this->toResponse($response);
            }

            return $response;
        } catch (\Throwable $e) {
            $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);

            return Response::json([
                'error' => $e->getMessage(),
            ])->withStatus(500);
        } finally {
            $trace->print();
            $scope->dispose();
        }
    }

    private function toResponse(mixed $data): ResponseInterface
    {
        if (is_array($data) || is_object($data)) {
            return Response::json($data);
        }

        if (is_string($data)) {
            return new Response(200, ['Content-Type' => 'text/plain'], $data);
        }

        return Response::json(['result' => $data]);
    }

    private function setupSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $shutdown = function (): void {
            if (!$this->running) {
                return;
            }

            $this->running = false;
            $this->app->trace()->log(TraceType::LifecycleShutdown, 'stopping');

            echo "\nShutting down...\n";

            $this->socket?->close();
            $this->app->shutdown();

            Loop::stop();
        };

        Loop::addSignal(SIGINT, $shutdown);
        Loop::addSignal(SIGTERM, $shutdown);
    }
}
