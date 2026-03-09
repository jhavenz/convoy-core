<?php

declare(strict_types=1);

/**
 * Simple HTTP Route
 *
 * Convoy's HttpRunner serves RouteGroup definitions using ReactPHP's
 * async HTTP server. Each request gets its own ExecutionScope.
 *
 * Routes are defined as invokables that receive ExecutionScope:
 * - Route closures return data (auto-JSON encoded)
 * - Route parameters available via $es->attribute('route.paramName')
 *
 * Run: php examples/01-beginner/04-simple-http-route.php
 * Test: curl http://localhost:8091/hello
 *       curl http://localhost:8091/hello/Convoy
 */

require_once __DIR__ . "/../_shared/bootstrap.php";

use Convoy\Application;
use Convoy\ExecutionScope;
use Convoy\Http\Route;
use Convoy\Http\RouteGroup;
use Convoy\Runner\HttpRunner;

$app = Application::starting()->compile();

$routes = RouteGroup::of([
    "GET /hello/{name}" => new Route(
        fn: static fn(ExecutionScope $es) => [
            "message" => "Hello, " . $es->attribute("route.name") . "!",
            "time" => date("c"),
        ],
    ),

    "GET /hello" => new Route(
        fn: static fn() => [
            "message" => "Hello, World!",
            "time" => date("c"),
        ],
    ),

    "GET /health" => new Route(
        fn: static fn(ExecutionScope $es) => [
            "status" => "ok",
        ],
    ),
]);

echo "Starting server on http://0.0.0.0:8091\n";
echo "Try: curl http://localhost:8091/hello\n";
echo "     curl http://localhost:8091/hello/YourName\n";

$runner = HttpRunner::withRoutes($app, $routes);
exit($runner->run("0.0.0.0:8091"));
