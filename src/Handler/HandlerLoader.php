<?php

declare(strict_types=1);

namespace Convoy\Handler;

use Closure;
use Convoy\Console\CommandGroup;
use Convoy\Http\RouteGroup;
use Convoy\Scope;
use RuntimeException;

/**
 * File-based handler discovery.
 *
 * Handler files return either:
 * - RouteGroup | CommandGroup | HandlerGroup directly
 * - Closure(Scope): RouteGroup|CommandGroup|HandlerGroup for dynamic loading
 *
 * @migration convoy/http, convoy/console
 *
 * Shared file-based discovery supporting both HTTP routes and CLI commands.
 * May remain in convoy-core as shared infrastructure, or be split if
 * discovery patterns diverge between protocols.
 */
final readonly class HandlerLoader
{
    /**
     * Load handlers from a single file.
     *
     * @param string $path Path to PHP file
     * @param Scope|null $scope For dynamic loading via closure
     */
    public static function load(string $path, ?Scope $scope = null): RouteGroup|CommandGroup|HandlerGroup
    {
        if (!is_file($path)) {
            throw new RuntimeException("Handler file not found: $path");
        }

        $result = require $path;

        if ($result instanceof RouteGroup || $result instanceof CommandGroup || $result instanceof HandlerGroup) {
            return $result;
        }

        if ($result instanceof Closure) {
            if ($scope === null) {
                throw new RuntimeException(
                    "Handler file returns closure but no scope provided: $path"
                );
            }

            $group = $result($scope);

            $validTypes = $group instanceof RouteGroup
                || $group instanceof CommandGroup
                || $group instanceof HandlerGroup;

            if (!$validTypes) {
                throw new RuntimeException(
                    "Handler closure must return RouteGroup, CommandGroup, or HandlerGroup, got: "
                    . get_debug_type($group)
                );
            }

            return $group;
        }

        throw new RuntimeException(
            "Handler file must return RouteGroup, CommandGroup, HandlerGroup, or Closure, got: "
            . get_debug_type($result)
        );
    }

    /**
     * Load routes from a single file.
     *
     * @param string $path Path to PHP file
     * @param Scope|null $scope For dynamic loading via closure
     */
    public static function loadRoutes(string $path, ?Scope $scope = null): RouteGroup
    {
        $result = self::load($path, $scope);

        if ($result instanceof RouteGroup) {
            return $result;
        }

        if ($result instanceof HandlerGroup) {
            return self::routeGroupFromHandlerGroup($result);
        }

        throw new RuntimeException(
            "Expected RouteGroup or HandlerGroup, got: " . get_debug_type($result)
        );
    }

    /**
     * Load commands from a single file.
     *
     * @param string $path Path to PHP file
     * @param Scope|null $scope For dynamic loading via closure
     */
    public static function loadCommands(string $path, ?Scope $scope = null): CommandGroup
    {
        $result = self::load($path, $scope);

        if ($result instanceof CommandGroup) {
            return $result;
        }

        if ($result instanceof HandlerGroup) {
            return self::commandGroupFromHandlerGroup($result);
        }

        throw new RuntimeException(
            "Expected CommandGroup or HandlerGroup, got: " . get_debug_type($result)
        );
    }

    /**
     * Load and merge all route files from a directory.
     *
     * Non-recursive. Only loads .php files.
     *
     * @param string $dir Directory path
     * @param Scope|null $scope For dynamic loading
     */
    public static function loadRouteDirectory(string $dir, ?Scope $scope = null): RouteGroup
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Handler directory not found: $dir");
        }

        $group = RouteGroup::create();
        $files = glob($dir . '/*.php');

        if ($files === false) {
            return $group;
        }

        sort($files);

        foreach ($files as $file) {
            $group = $group->merge(self::loadRoutes($file, $scope));
        }

        return $group;
    }

    /**
     * Load and merge all command files from a directory.
     *
     * Non-recursive. Only loads .php files.
     *
     * @param string $dir Directory path
     * @param Scope|null $scope For dynamic loading
     */
    public static function loadCommandDirectory(string $dir, ?Scope $scope = null): CommandGroup
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Handler directory not found: $dir");
        }

        $group = CommandGroup::create();
        $files = glob($dir . '/*.php');

        if ($files === false) {
            return $group;
        }

        sort($files);

        foreach ($files as $file) {
            $group = $group->merge(self::loadCommands($file, $scope));
        }

        return $group;
    }

    /**
     * Load and merge all handler files from a directory.
     *
     * Non-recursive. Only loads .php files.
     *
     * @param string $dir Directory path
     * @param Scope|null $scope For dynamic loading
     */
    #[\Deprecated(message: 'Use loadRouteDirectory or loadCommandDirectory for typed returns')]
    public static function loadDirectory(string $dir, ?Scope $scope = null): HandlerGroup
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Handler directory not found: $dir");
        }

        $group = HandlerGroup::create();
        $files = glob($dir . '/*.php');

        if ($files === false) {
            return $group;
        }

        sort($files);

        foreach ($files as $file) {
            $loaded = self::load($file, $scope);

            if ($loaded instanceof HandlerGroup) {
                $group = $group->merge($loaded);
            } elseif ($loaded instanceof RouteGroup) {
                $group = $group->merge($loaded->handlers());
            } elseif ($loaded instanceof CommandGroup) {
                $group = $group->merge($loaded->handlers());
            }
        }

        return $group;
    }

    /**
     * Load handlers matching a glob pattern.
     *
     * @param string $pattern Glob pattern (e.g., "handlers/*.php")
     * @param Scope|null $scope For dynamic loading
     */
    #[\Deprecated(message: 'Use specific route/command loading for typed returns')]
    public static function glob(string $pattern, ?Scope $scope = null): HandlerGroup
    {
        $files = glob($pattern);

        if ($files === false || $files === []) {
            return HandlerGroup::create();
        }

        sort($files);

        $group = HandlerGroup::create();

        foreach ($files as $file) {
            if (is_file($file)) {
                $loaded = self::load($file, $scope);

                if ($loaded instanceof HandlerGroup) {
                    $group = $group->merge($loaded);
                } elseif ($loaded instanceof RouteGroup) {
                    $group = $group->merge($loaded->handlers());
                } elseif ($loaded instanceof CommandGroup) {
                    $group = $group->merge($loaded->handlers());
                }
            }
        }

        return $group;
    }

    private static function routeGroupFromHandlerGroup(HandlerGroup $group): RouteGroup
    {
        $reflection = new \ReflectionClass(RouteGroup::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('inner');
        $property->setValue($instance, $group);

        return $instance;
    }

    private static function commandGroupFromHandlerGroup(HandlerGroup $group): CommandGroup
    {
        $reflection = new \ReflectionClass(CommandGroup::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('inner');
        $property->setValue($instance, $group);

        return $instance;
    }
}
