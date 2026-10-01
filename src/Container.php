<?php

declare(strict_types=1);

namespace EidCloud\Micro;

use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Lightweight PSR-11 compatible Dependency Injection Container & Service Locator.
 *
 * Supports singletons, factory bindings, recursive auto-wiring, and reflection-based method injection.
 */
class Container
{
    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $singletons = [];

    /**
     * Check if the container has a binding or instance for the given identifier.
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    /**
     * Resolve and retrieve an entry from the container.
     *
     * @throws RuntimeException if service cannot be resolved
     */
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $resolver = $this->bindings[$id];
            $value = is_callable($resolver) ? $resolver($this) : $resolver;

            if (!empty($this->singletons[$id])) {
                $this->instances[$id] = $value;
            }

            return $value;
        }

        // Attempt automatic instantiation if $id is an existing class name
        if (class_exists($id)) {
            $instance = $this->make($id);
            if (!empty($this->singletons[$id])) {
                $this->instances[$id] = $instance;
            }
            return $instance;
        }

        throw new RuntimeException("Service or binding '{$id}' not found in container.");
    }

    /**
     * Bind a key to a value or factory callable.
     */
    public function set(string $id, mixed $entry): static
    {
        $this->bindings[$id] = $entry;
        unset($this->instances[$id], $this->singletons[$id]);
        return $this;
    }

    /**
     * Register a shared singleton service (resolved once, then cached).
     */
    public function singleton(string $id, mixed $entry): static
    {
        if (!is_callable($entry) && is_object($entry)) {
            $this->instances[$id] = $entry;
        } else {
            $this->bindings[$id] = $entry;
        }
        $this->singletons[$id] = true;
        return $this;
    }

    /**
     * Instantiate a class with recursive constructor auto-wiring.
     *
     * @template T of object
     * @param class-string<T> $className
     * @param array<string, mixed> $parameters
     * @return T
     */
    public function make(string $className, array $parameters = []): object
    {
        if (!class_exists($className)) {
            throw new RuntimeException("Target class '{$className}' does not exist.");
        }

        $reflector = new ReflectionClass($className);
        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Target class '{$className}' is not instantiable.");
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $className();
        }

        $dependencies = $this->resolveParameters($constructor->getParameters(), $parameters);
        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Invoke a callable, auto-injecting dependencies from the container.
     *
     * @param callable|array|string $callable
     * @param array<string, mixed> $parameters
     * @return mixed
     */
    public function call(mixed $callable, array $parameters = []): mixed
    {
        if (is_array($callable)) {
            [$classOrObject, $method] = $callable;
            $object = is_string($classOrObject) ? $this->get($classOrObject) : $classOrObject;
            $reflection = new ReflectionMethod($object, $method);
            $args = $this->resolveParameters($reflection->getParameters(), $parameters);
            return $reflection->invokeArgs($object, $args);
        }

        if (is_string($callable) && str_contains($callable, '@')) {
            [$class, $method] = explode('@', $callable, 2);
            return $this->call([$this->get($class), $method], $parameters);
        }

        $reflection = new ReflectionFunction(\Closure::fromCallable($callable));
        $args = $this->resolveParameters($reflection->getParameters(), $parameters);
        return $callable(...$args);
    }

    /**
     * @param \ReflectionParameter[] $reflectionParams
     * @param array<string, mixed> $providedParams
     * @return list<mixed>
     */
    private function resolveParameters(array $reflectionParams, array $providedParams): array
    {
        $args = [];

        foreach ($reflectionParams as $param) {
            $name = $param->getName();

            // 1. Direct name match in provided parameters
            if (array_key_exists($name, $providedParams)) {
                $args[] = $providedParams[$name];
                continue;
            }

            // 2. Match in container by parameter name
            if ($this->has($name)) {
                $args[] = $this->get($name);
                continue;
            }

            // 3. Type-based resolution
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                
                // If provided param matches by class name
                if (isset($providedParams[$typeName])) {
                    $args[] = $providedParams[$typeName];
                    continue;
                }

                // If container contains the type or self
                if ($typeName === self::class || $typeName === Container::class) {
                    $args[] = $this;
                    continue;
                }

                if ($this->has($typeName)) {
                    $args[] = $this->get($typeName);
                    continue;
                }

                if (class_exists($typeName)) {
                    $args[] = $this->make($typeName);
                    continue;
                }
            }

            // 3. Fallback to default value if optional
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // 4. Nullable parameter fallback
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new RuntimeException("Unable to resolve parameter '\${$name}' for execution.");
        }

        return $args;
    }
}
