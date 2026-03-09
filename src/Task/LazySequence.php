<?php

declare(strict_types=1);

namespace Convoy\Task;

use Closure;
use Convoy\ExecutionScope;
use Convoy\Scope;
use Generator;

final readonly class LazySequence implements Dispatchable
{
    private function __construct(
        private Closure $factory,
    ) {
    }

    public static function from(Closure $factory): self
    {
        return new self($factory);
    }

    public static function of(iterable $items): self
    {
        return new self(static function (ExecutionScope $s) use ($items): Generator {
            yield from $items;
        });
    }

    public function map(Closure $fn): self
    {
        $source = $this->factory;
        return new self(static function (ExecutionScope $s) use ($source, $fn): Generator {
            foreach ($source($s) as $key => $value) {
                $s->throwIfCancelled();
                yield $key => $fn($value, $key, $s);
            }
        });
    }

    public function filter(Closure $predicate): self
    {
        $source = $this->factory;
        return new self(static function (ExecutionScope $s) use ($source, $predicate): Generator {
            foreach ($source($s) as $key => $value) {
                $s->throwIfCancelled();
                if ($predicate($value, $key, $s)) {
                    yield $key => $value;
                }
            }
        });
    }

    public function take(int $n): self
    {
        $source = $this->factory;
        return new self(static function (ExecutionScope $s) use ($source, $n): Generator {
            $count = 0;
            foreach ($source($s) as $key => $value) {
                if ($count >= $n) {
                    break;
                }
                $s->throwIfCancelled();
                yield $key => $value;
                $count++;
            }
        });
    }

    public function chunk(int $size): self
    {
        $source = $this->factory;
        return new self(static function (ExecutionScope $s) use ($source, $size): Generator {
            $chunk = [];
            foreach ($source($s) as $value) {
                $s->throwIfCancelled();
                $chunk[] = $value;
                if (count($chunk) >= $size) {
                    yield $chunk;
                    $chunk = [];
                }
            }
            if (!empty($chunk)) {
                yield $chunk;
            }
        });
    }

    public function mapConcurrent(Closure $fn, int $concurrency = 10): self
    {
        $source = $this->factory;
        return new self(static function (ExecutionScope $s) use ($source, $fn, $concurrency): Generator {
            $batch = [];
            foreach ($source($s) as $key => $value) {
                $batch[$key] = $value;

                if (count($batch) >= $concurrency) {
                    $results = $s->map(
                        items: $batch,
                        fn: static fn($v) => new Transform($v, static fn($val, $scope) => $fn($val, $scope)),
                        limit: $concurrency,
                    );
                    yield from $results;
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                $results = $s->map(
                    items: $batch,
                    fn: static fn($v) => new Transform($v, static fn($val, $scope) => $fn($val, $scope)),
                    limit: $concurrency,
                );
                yield from $results;
            }
        });
    }

    public function collect(): Collect
    {
        return new Collect($this);
    }

    public function reduce(Closure $fn, mixed $initial = null): Reduce
    {
        return new Reduce($this, $fn, $initial);
    }

    public function first(): First
    {
        return new First($this);
    }

    public function __invoke(Scope $scope): Generator
    {
        return ($this->factory)($scope);
    }
}
