<?php

declare(strict_types=1);

namespace EidCloud\Micro;

/**
 * Route definition with path, HTTP method, handler, and route-specific middleware pipeline.
 */
class Route
{
    private string $method;
    private string $path;
    private mixed $handler;
    /** @var array<int, callable|MiddlewareInterface|string> */
    private array $middleware = [];

    public function __construct(string $method, string $path, mixed $handler)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->handler = $handler;
    }

    /**
     * Attach middleware to this specific route.
     *
     * @param callable|MiddlewareInterface|string $middleware
     */
    public function add(callable|MiddlewareInterface|string $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * @return array<int, callable|MiddlewareInterface|string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
