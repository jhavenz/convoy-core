<?php

declare(strict_types=1);

namespace Convoy;

use Convoy\Concurrency\CancellationToken;
use Convoy\Middleware\ServiceTransform;
use Convoy\Middleware\TaskInterceptor;
use Convoy\Service\LazySingleton;
use Convoy\Service\ServiceBundle;
use Convoy\Service\ServiceCatalog;
use Convoy\Service\ServiceGraph;
use Convoy\Service\ServiceGraphCompiler;
use Convoy\Trace\Trace;
use Convoy\Trace\TraceType;

interface AppHost
{
    /** @return list<ServiceBundle> */
    public function providers(): array;

    public function createScope(?CancellationToken $token = null): Scope;

    public function startup(): void;

    public function shutdown(): void;
}

final class Application implements AppHost
{
    private bool $started = false;

    private function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly Trace $trace,
        /** @var list<ServiceBundle> */
        private readonly array $serviceProviders,
        /** @var list<TaskInterceptor> */
        private readonly array $taskInterceptors,
    ) {
    }

    /**
     * @param list<ServiceBundle> $providers
     * @param list<TaskInterceptor> $taskInterceptors
     */
    public static function create(
        ServiceGraph $graph,
        LazySingleton $singletons,
        Trace $trace,
        array $providers,
        array $taskInterceptors,
    ): self {
        return new self($graph, $singletons, $trace, $providers, $taskInterceptors);
    }

    /** @param array<string, mixed> $context */
    public static function starting(array $context = []): ApplicationBuilder
    {
        return new ApplicationBuilder($context);
    }

    /** @return list<ServiceBundle> */
    public function providers(): array
    {
        return $this->serviceProviders;
    }

    public function createScope(?CancellationToken $token = null): Scope
    {
        return new ExecutionScope(
            $this->graph,
            $this->singletons,
            $token ?? CancellationToken::none(),
            $this->trace,
            $this->taskInterceptors,
        );
    }

    public function startup(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->singletons->startup();
    }

    public function shutdown(): void
    {
        if (!$this->started) {
            return;
        }

        $this->singletons->shutdown();
        $this->started = false;
    }

    public function trace(): Trace
    {
        return $this->trace;
    }

    public function graph(): ServiceGraph
    {
        return $this->graph;
    }
}

final class ApplicationBuilder
{
    private bool $discover = false;

    private ?Trace $trace = null;

    /** @var list<ServiceBundle> */
    private array $providers = [];

    /** @var list<TaskInterceptor> */
    private array $taskInterceptors = [];

    /** @var list<ServiceTransform> */
    private array $serviceTransforms = [];

    /** @param array<string, mixed> $context */
    public function __construct(
        private readonly array $context,
    ) {
    }

    public function middleware(ServiceTransform ...$transforms): self
    {
        foreach ($transforms as $transform) {
            $this->serviceTransforms[] = $transform;
        }

        return $this;
    }

    public function taskMiddleware(TaskInterceptor ...$interceptors): self
    {
        foreach ($interceptors as $interceptor) {
            $this->taskInterceptors[] = $interceptor;
        }

        return $this;
    }

    public function discover(): self
    {
        $this->discover = true;
        return $this;
    }

    public function providers(ServiceBundle ...$providers): self
    {
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }

        return $this;
    }

    public function withTrace(Trace $trace): self
    {
        $this->trace = $trace;
        return $this;
    }

    public function compile(): Application
    {
        $trace = $this->trace ?? Trace::fromContext($this->context);
        $trace->log(TraceType::LifecycleStartup, 'compiling');

        $registry = new ServiceCatalog();

        if ($this->discover) {
            $this->loadDiscoveredProviders();
        }

        foreach ($this->providers as $provider) {
            $provider->services($registry, $this->context);
        }

        $compiler = new ServiceGraphCompiler();
        $graph = $compiler->compile($registry, $this->serviceTransforms, $this->context);

        $singletons = new LazySingleton($graph, $trace);

        return Application::create($graph, $singletons, $trace, $this->providers, $this->taskInterceptors);
    }

    private function loadDiscoveredProviders(): void
    {
        $vendorPath = $this->context['vendor_path']
            ?? ($this->context['project_dir'] ?? null) . '/vendor'
            ?? null;

        if ($vendorPath === null) {
            return;
        }

        $providersFile = $vendorPath . '/convoy/providers.php';
        if (!file_exists($providersFile)) {
            return;
        }
        $providers = require $providersFile;
        if (!is_array($providers)) {
            return;
        }
        foreach ($providers as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }

            $provider = new $providerClass();

            if ($provider instanceof ServiceBundle) {
                $this->providers[] = $provider;
            }
        }
    }
}
