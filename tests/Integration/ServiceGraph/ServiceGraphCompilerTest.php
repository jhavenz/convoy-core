<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\ServiceGraph;

use Convoy\Exception\CyclicDependencyException;
use Convoy\Exception\InvalidServiceConfigurationException;
use Convoy\Middleware\ServiceTransform;
use Convoy\Service\ServiceCatalog;
use Convoy\Service\ServiceDefinition;
use Convoy\Service\ServiceGraphCompiler;
use Convoy\Tests\Support\Fixtures\Database;
use Convoy\Tests\Support\Fixtures\IndependentService;
use Convoy\Tests\Support\Fixtures\Logger;
use Convoy\Tests\Support\Fixtures\ServiceA;
use Convoy\Tests\Support\Fixtures\ServiceB;
use Convoy\Tests\Support\Fixtures\ServiceC;
use Convoy\Tests\Support\Fixtures\ServiceD;
use Convoy\Tests\Support\Fixtures\ServiceE;
use Convoy\Tests\Support\Fixtures\SingletonWithScopedDep;
use Convoy\Tests\Support\Fixtures\ScopedService;
use Convoy\Tests\Support\Fixtures\UserRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServiceGraphCompilerTest extends TestCase
{
    private ServiceGraphCompiler $compiler;
    private ServiceCatalog $catalog;

    protected function setUp(): void
    {
        $this->compiler = new ServiceGraphCompiler();
        $this->catalog = new ServiceCatalog();
    }

    #[Test]
    public function detects_direct_cycle(): void
    {
        $this->catalog->singleton(ServiceA::class)
            ->needs(ServiceB::class);

        $this->catalog->singleton(ServiceB::class)
            ->needs(ServiceA::class);

        $this->expectException(CyclicDependencyException::class);
        $this->expectExceptionMessageMatches('/ServiceA.*ServiceB/');

        $this->compiler->compile($this->catalog, [], []);
    }

    #[Test]
    public function detects_indirect_cycle(): void
    {
        $this->catalog->singleton(ServiceC::class)
            ->needs(ServiceD::class);

        $this->catalog->singleton(ServiceD::class)
            ->needs(ServiceE::class);

        $this->catalog->singleton(ServiceE::class)
            ->needs(ServiceC::class);

        $this->expectException(CyclicDependencyException::class);

        $this->compiler->compile($this->catalog, [], []);
    }

    #[Test]
    public function allows_diamond_dependency(): void
    {
        $this->catalog->singleton(Logger::class);
        $this->catalog->singleton(Database::class)
            ->needs(Logger::class);
        $this->catalog->singleton(UserRepository::class)
            ->needs(Database::class, Logger::class);

        $graph = $this->compiler->compile($this->catalog, [], []);

        $this->assertTrue($graph->has(Logger::class));
        $this->assertTrue($graph->has(Database::class));
        $this->assertTrue($graph->has(UserRepository::class));
    }

    #[Test]
    public function rejects_singleton_depending_on_scoped(): void
    {
        $this->catalog->scoped(ScopedService::class);
        $this->catalog->singleton(SingletonWithScopedDep::class)
            ->needs(ScopedService::class);

        $this->expectException(InvalidServiceConfigurationException::class);
        $this->expectExceptionMessageMatches('/Singleton.*cannot depend on scoped/');

        $this->compiler->compile($this->catalog, [], []);
    }

    #[Test]
    public function allows_scoped_depending_on_singleton(): void
    {
        $this->catalog->singleton(Logger::class);
        $this->catalog->scoped(Database::class)
            ->needs(Logger::class);

        $graph = $this->compiler->compile($this->catalog, [], []);

        $this->assertTrue($graph->has(Database::class));
        $this->assertFalse($graph->resolve(Database::class)->singleton);
    }

    #[Test]
    public function resolves_dependency_order_correctly(): void
    {
        $this->catalog->singleton(Logger::class);
        $this->catalog->singleton(Database::class)
            ->needs(Logger::class);
        $this->catalog->singleton(UserRepository::class)
            ->needs(Database::class, Logger::class);

        $graph = $this->compiler->compile($this->catalog, [], []);

        $userRepoCompiled = $graph->resolve(UserRepository::class);

        $loggerIdx = array_search(Logger::class, $userRepoCompiled->dependencyOrder, true);
        $dbIdx = array_search(Database::class, $userRepoCompiled->dependencyOrder, true);

        $this->assertNotFalse($loggerIdx);
        $this->assertNotFalse($dbIdx);
        $this->assertLessThan($dbIdx, $loggerIdx, 'Logger should come before Database');
    }

    #[Test]
    public function detects_missing_dependency(): void
    {
        $this->catalog->singleton(Database::class)
            ->needs('NonExistent\\Service');

        $this->expectException(InvalidServiceConfigurationException::class);
        $this->expectExceptionMessageMatches('/requires.*NonExistent/');

        $this->compiler->compile($this->catalog, [], []);
    }

    #[Test]
    public function resolves_alias_in_dependency_check(): void
    {
        $this->catalog->singleton(Logger::class);
        $this->catalog->alias('LoggerInterface', Logger::class);
        $this->catalog->singleton(Database::class)
            ->needs('LoggerInterface');

        $graph = $this->compiler->compile($this->catalog, [], []);

        $this->assertTrue($graph->has(Database::class));
    }

    #[Test]
    public function applies_service_transforms(): void
    {
        $this->catalog->singleton(Logger::class);

        $transform = new class implements ServiceTransform {
            public function __invoke(ServiceDefinition $def): ServiceDefinition
            {
                if ($def->type === Logger::class) {
                    return $def->withTags('transformed');
                }
                return $def;
            }
        };

        $graph = $this->compiler->compile($this->catalog, [$transform], []);
        $compiled = $graph->resolve(Logger::class);

        $this->assertTrue($compiled->singleton);
    }

    #[Test]
    public function independent_services_compile_without_dependencies(): void
    {
        $this->catalog->singleton(IndependentService::class);
        $this->catalog->singleton(Logger::class);

        $graph = $this->compiler->compile($this->catalog, [], []);

        $this->assertTrue($graph->has(IndependentService::class));
        $this->assertEmpty($graph->resolve(IndependentService::class)->dependencyOrder);
    }
}
