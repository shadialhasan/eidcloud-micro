<?php

declare(strict_types=1);

namespace EidCloud\Micro;

/**
 * Ultra-fast hybrid Static-Hash and Regex/Trie Router.
 *
 * Supports static O(1) hash resolution, dynamic route parameters with custom regex
 * constraints (/users/{id:[0-9]+}), route groups, and automatic 405 detection.
 */
class Router
{
    /** @var array<string, array<string, Route>> [METHOD => [PATH => Route]] */
    private array $staticRoutes = [];

    /**
     * @var array<string, array<int, array{
     *   route: Route,
     *   regex: string,
     *   paramNames: list<string>
     * }>>
     */
    private array $dynamicRoutes = [];

    /** @var list<Route> */
    private array $routes = [];

    private string $currentPrefix = '';

    /**
     * Register a route with one or multiple HTTP methods.
     *
     * @param string|list<string> $methods
     * @param string $path
     * @param mixed $handler
     */
    public function addRoute(string|array $methods, string $path, mixed $handler): Route
    {
        $normalizedMethods = is_array($methods)
            ? array_map('strtoupper', $methods)
            : [strtoupper($methods)];

        $fullPath = $this->normalizePath($this->currentPrefix . '/' . ltrim($path, '/'));
        $route = new Route($normalizedMethods[0], $fullPath, $handler);
        $this->routes[] = $route;

        $isDynamic = str_contains($fullPath, '{');

        foreach ($normalizedMethods as $method) {
            if (!$isDynamic) {
                $this->staticRoutes[$method][$fullPath] = $route;
            } else {
                $compiled = $this->compileDynamicRoute($fullPath);
                $this->dynamicRoutes[$method][] = [
                    'route' => $route,
                    'regex' => $compiled['regex'],
                    'paramNames' => $compiled['paramNames'],
                ];
            }
        }

        return $route;
    }

    public function get(string $path, mixed $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    public function options(string $path, mixed $handler): Route
    {
        return $this->addRoute('OPTIONS', $path, $handler);
    }

    public function any(string $path, mixed $handler): Route
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'], $path, $handler);
    }

    /**
     * Group routes under a shared URI prefix.
     */
    public function group(string $prefix, callable $callback): void
    {
        $previousPrefix = $this->currentPrefix;
        $this->currentPrefix = $this->normalizePath($previousPrefix . '/' . trim($prefix, '/'));

        $callback($this);

        $this->currentPrefix = $previousPrefix;
    }

    /**
     * Dispatch HTTP method and URI path against registered routes.
     */
    public function dispatch(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $cleanPath = $this->normalizePath(parse_url($path, PHP_URL_PATH) ?? '/');

        // 1. Fast O(1) static route lookup
        if (isset($this->staticRoutes[$method][$cleanPath])) {
            return RouteMatch::found($this->staticRoutes[$method][$cleanPath], []);
        }

        // 2. Dynamic regex/trie route matching
        if (isset($this->dynamicRoutes[$method])) {
            foreach ($this->dynamicRoutes[$method] as $entry) {
                if (preg_match($entry['regex'], $cleanPath, $matches)) {
                    $params = [];
                    foreach ($entry['paramNames'] as $paramName) {
                        if (isset($matches[$paramName])) {
                            $params[$paramName] = $matches[$paramName];
                        }
                    }
                    return RouteMatch::found($entry['route'], $params);
                }
            }
        }

        // 3. Check for 405 Method Not Allowed across other registered methods
        $allowedMethods = [];

        foreach ($this->staticRoutes as $otherMethod => $paths) {
            if ($otherMethod !== $method && isset($paths[$cleanPath])) {
                $allowedMethods[] = $otherMethod;
            }
        }

        foreach ($this->dynamicRoutes as $otherMethod => $entries) {
            if ($otherMethod !== $method) {
                foreach ($entries as $entry) {
                    if (preg_match($entry['regex'], $cleanPath)) {
                        $allowedMethods[] = $otherMethod;
                        break;
                    }
                }
            }
        }

        if (!empty($allowedMethods)) {
            return RouteMatch::methodNotAllowed(array_values(array_unique($allowedMethods)));
        }

        // 4. No matching route found
        return RouteMatch::notFound();
    }

    /**
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Clean and normalize URI path.
     */
    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /**
     * Compile parameterized path (e.g. /users/{id:\d+} or /orders/{code:[A-Z]{3}-\d+}) into regex.
     *
     * @return array{regex: string, paramNames: list<string>}
     */
    private function compileDynamicRoute(string $path): array
    {
        $paramNames = [];
        $len = strlen($path);
        $regex = '';
        $i = 0;

        while ($i < $len) {
            if ($path[$i] === '{') {
                $closePos = $this->findMatchingBrace($path, $i);
                if ($closePos !== false) {
                    $segment = substr($path, $i + 1, $closePos - $i - 1);
                    $colonPos = strpos($segment, ':');

                    if ($colonPos !== false) {
                        $name = substr($segment, 0, $colonPos);
                        $pattern = substr($segment, $colonPos + 1);
                    } else {
                        $name = $segment;
                        $pattern = '[^/]+';
                    }

                    $paramNames[] = $name;
                    $regex .= '(?P<' . $name . '>' . $pattern . ')';
                    $i = $closePos + 1;
                    continue;
                }
            }

            $regex .= preg_quote($path[$i], '#');
            $i++;
        }

        return [
            'regex' => '#^' . $regex . '$#u',
            'paramNames' => $paramNames,
        ];
    }

    /**
     * Find matching closing brace supporting nested braces in regex (e.g. {code:[A-Z]{3}}).
     */
    private function findMatchingBrace(string $str, int $start): int|false
    {
        $len = strlen($str);
        $depth = 0;

        for ($i = $start; $i < $len; $i++) {
            if ($str[$i] === '{') {
                $depth++;
            } elseif ($str[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }
}
