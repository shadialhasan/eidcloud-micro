<?php

declare(strict_types=1);

namespace EidCloud\Micro\Middleware;

use EidCloud\Micro\MiddlewareInterface;
use EidCloud\Micro\Request;
use EidCloud\Micro\Response;

/**
 * Cross-Origin Resource Sharing (CORS) Middleware.
 *
 * Adds CORS headers to responses and short-circuits OPTIONS preflight requests.
 */
class CorsMiddleware implements MiddlewareInterface
{
    private string $origin;
    private string $methods;
    private string $headers;
    private int $maxAge;
    private bool $allowCredentials;

    public function __construct(
        string $origin = '*',
        string $methods = 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
        string $headers = 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
        int $maxAge = 86400,
        bool $allowCredentials = false
    ) {
        $this->origin = $origin;
        $this->methods = $methods;
        $this->headers = $headers;
        $this->maxAge = $maxAge;
        $this->allowCredentials = $allowCredentials;
    }

    public function process(Request $request, callable $next): Response
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => $this->origin,
            'Access-Control-Allow-Methods' => $this->methods,
            'Access-Control-Allow-Headers' => $this->headers,
            'Access-Control-Max-Age' => (string) $this->maxAge,
        ];

        if ($this->allowCredentials) {
            $corsHeaders['Access-Control-Allow-Credentials'] = 'true';
        }

        // Handle OPTIONS preflight immediately
        if ($request->getMethod() === 'OPTIONS') {
            return Response::empty(204, $corsHeaders);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response->withHeaders($corsHeaders);
    }
}
