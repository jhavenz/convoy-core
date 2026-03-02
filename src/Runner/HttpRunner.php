<?php

declare(strict_types=1);

namespace Convoy\Runner;

use Convoy\AppHost;
use Convoy\Concurrency\CancellationToken;
use Convoy\Trace\TraceType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

final class HttpRunner
{
    private ?HttpServer $server = null;
    private ?SocketServer $socket = null;
    private bool $running = false;

    public function __construct(
        private readonly AppHost $app,
        private readonly string $host,
        private readonly int $port,
        /** @var callable(ServerRequestInterface, Scope): mixed */
        private $handler,
        private readonly float $requestTimeout = 30.0,
    ) {
    }

    public function run(): int
    {
        $this->app->startup();

        $this->server = new HttpServer(function (ServerRequestInterface $request): ResponseInterface {
            return $this->handleRequest($request);
        });

        $uri = "$this->host:$this->port";
        $this->socket = new SocketServer($uri);
        $this->server->listen($this->socket);

        $this->running = true;

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

    /** @return list<string> */
    private function formatTrace(\Throwable $e): array
    {
        $trace = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $func = $frame['function'];
            $class = $frame['class'] ?? '';

            $trace[] = "$class$func at $file:$line";
        }

        return array_slice($trace, 0, 10);
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

            echo "\nShutting down...\n";

            $this->socket?->close();
            $this->app->shutdown();

            Loop::stop();
        };

        Loop::addSignal(SIGINT, $shutdown);
        Loop::addSignal(SIGTERM, $shutdown);
    }
}
