<?php

declare(strict_types=1);

namespace EidCloud\Micro;

/**
 * MiddlewareInterface defines standard onion-layer middleware.
 *
 * Middleware wraps request handling allowing pre-processing,
 * post-processing, header mutation, short-circuit responses, etc.
 */
interface MiddlewareInterface
{
    /**
     * Process an incoming server request.
     *
     * @param Request $request Current HTTP request
     * @param callable(Request): Response $next Next middleware or route handler in chain
     * @return Response Resulting HTTP response
     */
    public function process(Request $request, callable $next): Response;
}
