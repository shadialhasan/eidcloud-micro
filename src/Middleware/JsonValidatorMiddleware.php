<?php

declare(strict_types=1);

namespace EidCloud\Micro\Middleware;

use EidCloud\Micro\MiddlewareInterface;
use EidCloud\Micro\Request;
use EidCloud\Micro\Response;

/**
 * JSON Request Validation Middleware.
 *
 * Enforces valid JSON body formatting for POST/PUT/PATCH requests.
 * Returns HTTP 400 Bad Request if the payload contains malformed JSON syntax.
 */
class JsonValidatorMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $methods;
    private bool $strictContentType;

    /**
     * @param list<string> $methods HTTP methods to enforce validation on
     * @param bool $strictContentType Require application/json Content-Type header
     */
    public function __construct(
        array $methods = ['POST', 'PUT', 'PATCH'],
        bool $strictContentType = false
    ) {
        $this->methods = array_map('strtoupper', $methods);
        $this->strictContentType = $strictContentType;
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->getMethod(), $this->methods, true)) {
            $contentType = $request->getHeader('content-type', '');
            $isJsonHeader = str_contains(strtolower($contentType), 'application/json');

            if ($this->strictContentType && !$isJsonHeader) {
                return Response::json([
                    'error' => 'Unsupported Media Type',
                    'message' => 'Content-Type must be application/json',
                    'status' => 415,
                ], 415);
            }

            $rawBody = $request->getBody();

            // Validate non-empty body
            if ($rawBody !== null && trim($rawBody) !== '') {
                try {
                    json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    return Response::json([
                        'error' => 'Bad Request',
                        'message' => 'Malformed or invalid JSON payload: ' . $e->getMessage(),
                        'status' => 400,
                    ], 400);
                }
            }
        }

        return $next($request);
    }
}
