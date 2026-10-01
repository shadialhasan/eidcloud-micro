<?php

declare(strict_types=1);

namespace EidCloud\Micro;

use EidCloud\Micro\Middleware\CorsMiddleware;
use EidCloud\Micro\Middleware\ExecutionTimerMiddleware;
use EidCloud\Micro\Middleware\JsonValidatorMiddleware;
use Throwable;

/**
 * EidCloud Micro Application Kernel.
 *
 * Ultra-fast, minimal micro-framework engine managing routing,
 * onion middleware pipeline, dependency injection, and HTTP lifecycles.
 */
class App
{
    private Router $router;
    private Container $container;

    /** @var list<callable|MiddlewareInterface|string> */
    private array $middlewares = [];

    /** @var callable|null */
    private $errorHandler = null;

    private bool $debug = false;

    public function __construct(?Container $container = null, ?Router $router = null)
    {
        $this->container = $container ?? new Container();
        $this->router = $router ?? new Router();

        // Bind core instances into container
        $this->container->singleton(self::class, $this);
        $this->container->singleton(Container::class, $this->container);
        $this->container->singleton(Router::class, $this->router);
    }

    /**
     * Factory helper to instantiate a new application.
     */
    public static function create(?Container $container = null, ?Router $router = null): self
    {
        return new self($container, $router);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Register global onion middleware.
     *
     * @param callable|MiddlewareInterface|string $middleware
     */
    public function add(callable|MiddlewareInterface|string $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Enable standard CORS middleware with optional settings.
     */
    public function enableCors(
        string $origin = '*',
        string $methods = 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
        string $headers = 'Content-Type, Authorization, X-Requested-With, Accept, Origin'
    ): self {
        return $this->add(new CorsMiddleware($origin, $methods, $headers));
    }

    /**
     * Enable execution time measurement in response headers.
     */
    public function enableTimer(string $headerName = 'X-Response-Time'): self
    {
        return $this->add(new ExecutionTimerMiddleware($headerName));
    }

    /**
     * Enable JSON payload validator for mutating HTTP methods.
     */
    public function enableJsonValidator(bool $strictContentType = false): self
    {
        return $this->add(new JsonValidatorMiddleware(strictContentType: $strictContentType));
    }

    /**
     * Define custom error handler callback: fn(Throwable $e, Request $req): Response
     */
    public function onError(callable $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    public function get(string $path, mixed $handler): Route
    {
        return $this->router->get($path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->router->post($path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->router->put($path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->router->delete($path, $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->router->patch($path, $handler);
    }

    public function options(string $path, mixed $handler): Route
    {
        return $this->router->options($path, $handler);
    }

    public function any(string $path, mixed $handler): Route
    {
        return $this->router->any($path, $handler);
    }

    /**
     * Group routes under a shared prefix.
     */
    public function group(string $prefix, callable $callback): void
    {
        $this->router->group($prefix, $callback);
    }

    /**
     * Process an HTTP Request through the middleware onion pipeline and route handler.
     */
    public function handle(Request $request): Response
    {
        try {
            // Dispatch route first to discover matched route and route-specific middleware
            $match = $this->router->dispatch($request->getMethod(), $request->getPath());

            if ($match->isFound()) {
                $route = $match->getRoute();
                $params = $match->getParams();
                $request = $request->withParams($params);
                $routeMiddleware = $match->getMiddleware();
                $handler = $match->getHandler();

                $coreHandler = function (Request $req) use ($handler, $params): Response {
                    $invocableParams = array_merge($params, [
                        'request' => $req,
                        Request::class => $req,
                    ]);

                    $result = $this->container->call($handler, $invocableParams);
                    return $this->normalizeResponse($result);
                };
            } elseif ($match->isMethodNotAllowed()) {
                $routeMiddleware = [];
                $allowed = $match->getAllowedMethods();
                $coreHandler = function (Request $req) use ($allowed): Response {
                    return Response::json([
                        'error' => 'Method Not Allowed',
                        'message' => "Method {$req->getMethod()} not allowed for {$req->getPath()}",
                        'allowed' => $allowed,
                        'status' => 405,
                    ], 405, ['Allow' => implode(', ', $allowed)]);
                };
            } else {
                $routeMiddleware = [];
                $coreHandler = function (Request $req): Response {
                    return Response::json([
                        'error' => 'Not Found',
                        'message' => "Route not found for {$req->getMethod()} {$req->getPath()}",
                        'status' => 404,
                    ], 404);
                };
            }

            // Combine global middleware + route-specific middleware into onion pipeline
            $pipelineMiddlewares = array_merge($this->middlewares, $routeMiddleware);
            $pipeline = $coreHandler;

            for ($i = count($pipelineMiddlewares) - 1; $i >= 0; $i--) {
                $middleware = $pipelineMiddlewares[$i];
                $next = $pipeline;
                $pipeline = fn(Request $req): Response => $this->executeMiddleware($middleware, $req, $next);
            }

            return $pipeline($request);
        } catch (Throwable $e) {
            return $this->handleException($e, $request);
        }
    }

    /**
     * Run the application by capturing the server request, processing, and sending response.
     */
    public function run(?Request $request = null): void
    {
        $request ??= Request::capture();
        $response = $this->handle($request);
        $response->send();
    }

    /**
     * Execute a single middleware step.
     */
    private function executeMiddleware(
        callable|MiddlewareInterface|string $middleware,
        Request $request,
        callable $next
    ): Response {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware->process($request, $next);
        }

        if (is_string($middleware) && class_exists($middleware)) {
            $instance = $this->container->get($middleware);
            if ($instance instanceof MiddlewareInterface) {
                return $instance->process($request, $next);
            }
            if (is_callable($instance)) {
                $res = $instance($request, $next);
                return $this->normalizeResponse($res);
            }
        }

        if (is_callable($middleware)) {
            $res = $middleware($request, $next);
            return $this->normalizeResponse($res);
        }

        return $next($request);
    }

    /**
     * Convert primitive return types (array, string, null) to a Response instance.
     */
    private function normalizeResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || $result instanceof \JsonSerializable || $result instanceof \stdClass) {
            return Response::json($result);
        }

        if (is_string($result)) {
            return Response::text($result);
        }

        if ($result === null) {
            return Response::empty(204);
        }

        if (is_scalar($result)) {
            return Response::text((string) $result);
        }

        return Response::json($result);
    }

    /**
     * Catch-all exception handling.
     */
    private function handleException(Throwable $e, Request $request): Response
    {
        if ($this->errorHandler !== null) {
            $customResponse = ($this->errorHandler)($e, $request);
            if ($customResponse instanceof Response) {
                return $customResponse;
            }
        }

        $payload = [
            'error' => 'Internal Server Error',
            'message' => $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
            'status' => 500,
        ];

        if ($this->debug) {
            $payload['exception'] = get_class($e);
            $payload['file'] = $e->getFile() . ':' . $e->getLine();
            $payload['trace'] = explode("\n", $e->getTraceAsString());
        }

        return Response::json($payload, 500);
    }
}
