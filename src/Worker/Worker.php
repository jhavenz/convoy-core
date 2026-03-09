<?php

declare(strict_types=1);

namespace Convoy\Worker;

use Convoy\Worker\Protocol\Codec;
use Convoy\Worker\Protocol\MessageType;
use Convoy\Worker\Protocol\Response;
use Convoy\Worker\Protocol\ServiceCall;
use Convoy\Worker\Protocol\TaskRequest;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class Worker
{
    private ?Process $process = null;
    private string $buffer = '';
    private ?Deferred $pendingTask = null;
    private ?string $pendingTaskId = null;
    private WorkerState $state = WorkerState::Idle;

    /** @var array<string, Deferred> */
    private array $pendingServiceCalls = [];

    /** @var callable(ServiceCall): PromiseInterface */
    private $serviceHandler;

    public function __construct(
        private readonly string $workerScript,
        private readonly LoopInterface $loop,
        private readonly string $autoloadPath,
    ) {
        $this->serviceHandler = static fn() => reject(new \RuntimeException('No service handler'));
    }

    public function state(): WorkerState
    {
        return $this->state;
    }

    /** @param callable(ServiceCall): PromiseInterface $handler */
    public function setServiceHandler(callable $handler): void
    {
        $this->serviceHandler = $handler;
    }

    public function start(): void
    {
        if ($this->process !== null && $this->process->isRunning()) {
            return;
        }

        $cmd = sprintf(
            'exec php %s --autoload=%s',
            escapeshellarg($this->workerScript),
            escapeshellarg($this->autoloadPath),
        );

        $this->process = new Process($cmd);
        $this->process->start($this->loop);
        $this->state = WorkerState::Idle;
        $this->buffer = '';

        $this->process->stdout->on('data', function (string $data): void {
            $this->buffer .= $data;
            $this->processBuffer();
        });

        $this->process->stderr->on('data', function (string $data): void {
            error_log("[Worker STDERR] $data");
        });

        $this->process->on('exit', function (?int $code, $signal): void {
            if ($this->state !== WorkerState::Draining) {
                $this->state = WorkerState::Crashed;
            }

            if ($this->pendingTask !== null) {
                $this->pendingTask->reject(
                    new \RuntimeException("Worker exited with code $code")
                );
                $this->pendingTask = null;
                $this->pendingTaskId = null;
            }

            foreach ($this->pendingServiceCalls as $deferred) {
                $deferred->reject(new \RuntimeException('Worker exited'));
            }
            $this->pendingServiceCalls = [];
        });
    }

    public function execute(TaskRequest $task): PromiseInterface
    {
        if ($this->state !== WorkerState::Idle) {
            return reject(new \RuntimeException("Worker not idle: {$this->state->name}"));
        }

        if ($this->process === null || !$this->process->isRunning()) {
            return reject(new \RuntimeException('Worker not running'));
        }

        $this->state = WorkerState::Busy;
        $this->pendingTaskId = $task->id;
        $this->pendingTask = new Deferred();

        $this->process->stdin->write(Codec::encode($task));

        return $this->pendingTask->promise()->finally(function (): void {
            if ($this->state === WorkerState::Busy) {
                $this->state = WorkerState::Idle;
            }
        });
    }

    public function drain(): PromiseInterface
    {
        if ($this->process === null || !$this->process->isRunning()) {
            return resolve(null);
        }

        $this->state = WorkerState::Draining;
        $deferred = new Deferred();

        $this->process->on('exit', static function () use ($deferred): void {
            $deferred->resolve(null);
        });

        $this->process->stdin->end();

        $this->loop->addTimer(5.0, function () use ($deferred): void {
            if ($this->process?->isRunning()) {
                $this->process->terminate(SIGTERM);
            }
        });

        $this->loop->addTimer(10.0, function () use ($deferred): void {
            if ($this->process?->isRunning()) {
                $this->process->terminate(SIGKILL);
            }
        });

        return $deferred->promise();
    }

    public function kill(): void
    {
        if ($this->process?->isRunning()) {
            $this->process->terminate(SIGKILL);
        }

        $this->state = WorkerState::Crashed;
        $this->process = null;
    }

    private function processBuffer(): void
    {
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            if (trim($line) === '') {
                continue;
            }

            try {
                $message = Codec::decode($line);
                $this->handleMessage($message);
            } catch (\Throwable $e) {
                error_log("[Worker] Failed to decode message: {$e->getMessage()}");
            }
        }
    }

    private function handleMessage(TaskRequest|ServiceCall|Response $message): void
    {
        if ($message instanceof ServiceCall) {
            $this->handleServiceCall($message);
            return;
        }

        if ($message instanceof Response) {
            if ($message->type === MessageType::ServiceResponse) {
                $this->handleServiceResponse($message);
                return;
            }

            if ($message->type === MessageType::TaskResponse) {
                $this->handleTaskResponse($message);
                return;
            }
        }
    }

    private function handleServiceCall(ServiceCall $call): void
    {
        $handler = $this->serviceHandler;
        $handler($call)->then(
            function (mixed $result) use ($call): void {
                $response = Response::serviceOk($call->id, $result);
                $this->process?->stdin->write(Codec::encode($response));
            },
            function (\Throwable $e) use ($call): void {
                $response = Response::serviceErr($call->id, $e);
                $this->process?->stdin->write(Codec::encode($response));
            },
        );
    }

    private function handleServiceResponse(Response $response): void
    {
        $deferred = $this->pendingServiceCalls[$response->id] ?? null;

        if ($deferred === null) {
            return;
        }

        unset($this->pendingServiceCalls[$response->id]);

        if ($response->ok) {
            $deferred->resolve($response->result);
        } else {
            $deferred->reject(new \RuntimeException($response->errorMessage ?? 'Service call failed'));
        }
    }

    private function handleTaskResponse(Response $response): void
    {
        if ($this->pendingTask === null || $this->pendingTaskId !== $response->id) {
            return;
        }

        $deferred = $this->pendingTask;
        $this->pendingTask = null;
        $this->pendingTaskId = null;

        if ($response->ok) {
            $deferred->resolve($response->result);
        } else {
            $deferred->reject(new \RuntimeException($response->errorMessage ?? 'Task failed'));
        }
    }
}
