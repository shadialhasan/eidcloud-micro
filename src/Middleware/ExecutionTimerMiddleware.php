<?php

declare(strict_types=1);

namespace EidCloud\Micro\Middleware;

use EidCloud\Micro\MiddlewareInterface;
use EidCloud\Micro\Request;
use EidCloud\Micro\Response;

/**
 * Execution Timer Middleware.
 *
 * Measures request lifecycle processing duration and appends
 * the 'X-Response-Time' header (e.g. '0.84ms') to the outgoing response.
 */
class ExecutionTimerMiddleware implements MiddlewareInterface
{
    private string $headerName;
    private int $precision;

    public function __construct(string $headerName = 'X-Response-Time', int $precision = 2)
    {
        $this->headerName = $headerName;
        $this->precision = $precision;
    }

    public function process(Request $request, callable $next): Response
    {
        $startTime = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        $endTime = hrtime(true);
        $durationMs = ($endTime - $startTime) / 1e+6;

        return $response->withHeader(
            $this->headerName,
            sprintf('%.*fms', $this->precision, $durationMs)
        );
    }
}
