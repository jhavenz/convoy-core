<?php

declare(strict_types=1);

namespace Convoy\Worker;

use Convoy\Service\LazySingleton;
use Convoy\Service\ServiceGraph;
use Convoy\Task\Executable;
use Convoy\Task\Scopeable;
use Convoy\Worker\Protocol\TaskRequest;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use ReflectionClass;

final class WorkerBridge
{
    private ?Worker $worker = null;
    private ?ParentServiceProxy $serviceProxy = null;
    private readonly string $workerScript;

    public function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly LoopInterface $loop,
        private readonly string $autoloadPath,
        ?string $workerScript = null,
    ) {
        $this->workerScript = $workerScript ?? $this->defaultWorkerScript();
    }

    /**
     * @param array<string, mixed> $contextAttrs
     */
    public function dispatch(
        Scopeable|Executable $task,
        array $contextAttrs = [],
    ): PromiseInterface {
        $this->ensureWorkerStarted();

        $request = $this->serializeTask($task, $contextAttrs);

        return $this->worker->execute($request);
    }

    public function drain(): PromiseInterface
    {
        if ($this->worker === null) {
            return \React\Promise\resolve(null);
        }

        return $this->worker->drain();
    }

    public function kill(): void
    {
        $this->worker?->kill();
        $this->worker = null;
    }

    private function ensureWorkerStarted(): void
    {
        if ($this->worker !== null && $this->worker->state() !== WorkerState::Crashed) {
            return;
        }

        $this->serviceProxy ??= new ParentServiceProxy($this->graph, $this->singletons);

        $this->worker = new Worker(
            workerScript: $this->workerScript,
            loop: $this->loop,
            autoloadPath: $this->autoloadPath,
        );

        $this->worker->setServiceHandler(fn($call) => $this->serviceProxy->handle($call));
        $this->worker->start();
    }

    /**
     * @param array<string, mixed> $contextAttrs
     */
    private function serializeTask(Scopeable|Executable $task, array $contextAttrs): TaskRequest
    {
        $class = $task::class;
        $args = $this->extractConstructorArgs($task);

        return new TaskRequest(
            id: bin2hex(random_bytes(8)),
            taskClass: $class,
            constructorArgs: $args,
            contextAttrs: $contextAttrs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function extractConstructorArgs(object $task): array
    {
        $reflection = new ReflectionClass($task);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (!$reflection->hasProperty($name)) {
                continue;
            }

            $prop = $reflection->getProperty($name);
            $prop->setAccessible(true);

            $value = $prop->getValue($task);

            if (!$this->isSerializable($value)) {
                $taskClass = $task::class;
                throw new \RuntimeException(
                    "Cannot serialize task $taskClass: property '$name' is not serializable"
                );
            }

            $args[$name] = $value;
        }

        return $args;
    }

    private function isSerializable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->isSerializable($item)) {
                    return false;
                }
            }
            return true;
        }

        if (is_object($value)) {
            if ($value instanceof \Closure) {
                return false;
            }

            if ($value instanceof \UnitEnum) {
                return true;
            }

            try {
                json_encode($value, JSON_THROW_ON_ERROR);
                return true;
            } catch (\JsonException) {
                return false;
            }
        }

        return false;
    }

    private function defaultWorkerScript(): string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/bin/convoy-worker',
            dirname(__DIR__, 4) . '/bin/convoy-worker',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }
}
