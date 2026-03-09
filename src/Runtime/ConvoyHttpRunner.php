<?php

declare(strict_types=1);

namespace Convoy\Runtime;

use Convoy\AppHost;
use Convoy\Concurrency\CancellationToken;
use Convoy\Support\SignalHandler;
use Convoy\Task\Executable;
use Convoy\Trace\TraceType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Symfony Runtime HTTP runner using ReactPHP.
 *
 * @migration convoy/http
 *
 * Will migrate to the convoy/http library along with request handling,
 * routing, and middleware infrastructure.
 */
final class ConvoyHttpRunner implements RunnerInterface
{
    private ?HttpServer $server = null;
    private ?SocketServer $socket = null;
    private ?TimerInterface $windowsTimer = null;
    private bool $running = false;
    private bool $shutdownRequested = false;
    private ?Executable $handler = null;

    public function __construct(
        private readonly AppHost $app,
        private readonly string $host,
        private readonly int $port,
        private readonly float $requestTimeout = 30.0,
    ) {
    }

    public function withHandler(Executable $handler): self
    {
        $clone = clone $this;
        $clone->handler = $handler;
        return $clone;
    }

    public function run(): int
    {
        if ($this->handler === null) {
            throw new \LogicException('No request handler configured. Call withHandler() before run().');
        }

        $this->app->startup();

        $uri = "{$this->host}:{$this->port}";
        $this->socket = new SocketServer($uri);
        $this->server = new HttpServer($this->handleRequest(...));
        $this->server->listen($this->socket);

        $this->running = true;
        $this->app->trace()->log(TraceType::LifecycleStartup, 'ready', ['uri' => $uri]);

        echo "Convoy HTTP server listening on http://$uri\n";

        $this->setupSignalHandlers();
        $this->setupWindowsShutdownCheck();

        Loop::run();

        return 0;
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        assert($this->handler !== null);

        $token = CancellationToken::timeout($this->requestTimeout);
        $scope = $this->app->createScope($token);
        $scope = $scope->withAttribute('request', $request);
        $trace = $scope->trace();
        $trace->reset();

        try {
            $response = $scope->execute($this->handler);

            if ($response instanceof ResponseInterface) {
                return $response;
            }

            return $this->toResponse($response);
        } catch (\Throwable $e) {
            $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);

            return Response::json([
                'error' => $e->getMessage(),
                'trace' => $this->formatTrace($e),
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
        SignalHandler::register($this->createShutdownHandler());
    }

    private function setupWindowsShutdownCheck(): void
    {
        if (!SignalHandler::isWindows()) {
            return;
        }

        $this->windowsTimer = Loop::addPeriodicTimer(0.1, function () {
            if ($this->shutdownRequested) {
                Loop::stop();
            }
        });
    }

    /** Intentionally captures $this - runner is process-scoped, no leak risk. */
    private function createShutdownHandler(): callable
    {
        return function (): void {
            $this->shutdown();
        };
    }

    private function shutdown(): void
    {
        if (!$this->running) {
            return;
        }

        $this->shutdownRequested = true;
        $this->running = false;

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'shutdown');
        echo "\nShutting down...\n";

        if ($this->windowsTimer !== null) {
            Loop::cancelTimer($this->windowsTimer);
            $this->windowsTimer = null;
        }

        $this->socket?->close();
        $this->app->shutdown();

        if (!SignalHandler::isWindows()) {
            Loop::stop();
        }
    }

    /**
     * @return list<string>
     */
    private function formatTrace(\Throwable $e): array
    {
        $trace = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $func = $frame['function'];
            $class = isset($frame['class']) ? $frame['class'] . '::' : '';
            $trace[] = "{$class}{$func} at {$file}:{$line}";
        }

        return array_slice($trace, 0, 10);
    }
}
