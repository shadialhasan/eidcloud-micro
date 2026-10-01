<?php

declare(strict_types=1);

namespace EidCloud\Micro;

/**
 * Result of router dispatching.
 */
class RouteMatch
{
    public const FOUND = 1;
    public const NOT_FOUND = 2;
    public const METHOD_NOT_ALLOWED = 3;

    private int $status;
    private ?Route $route;
    /** @var array<string, string> */
    private array $params;
    /** @var list<string> */
    private array $allowedMethods;

    /**
     * @param int $status Match status constant (FOUND, NOT_FOUND, METHOD_NOT_ALLOWED)
     * @param Route|null $route Matched route if found
     * @param array<string, string> $params Extracted URI path parameters
     * @param list<string> $allowedMethods List of allowed HTTP methods for this URI if 405
     */
    public function __construct(
        int $status,
        ?Route $route = null,
        array $params = [],
        array $allowedMethods = []
    ) {
        $this->status = $status;
        $this->route = $route;
        $this->params = $params;
        $this->allowedMethods = $allowedMethods;
    }

    public static function found(Route $route, array $params = []): self
    {
        return new self(self::FOUND, $route, $params);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], $allowedMethods);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }

    public function isNotFound(): bool
    {
        return $this->status === self::NOT_FOUND;
    }

    public function isMethodNotAllowed(): bool
    {
        return $this->status === self::METHOD_NOT_ALLOWED;
    }

    public function getRoute(): ?Route
    {
        return $this->route;
    }

    public function getHandler(): mixed
    {
        return $this->route?->getHandler();
    }

    /**
     * @return array<int, callable|MiddlewareInterface|string>
     */
    public function getMiddleware(): array
    {
        return $this->route?->getMiddleware() ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return list<string>
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
