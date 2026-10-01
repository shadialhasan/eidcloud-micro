<?php

declare(strict_types=1);

namespace EidCloud\Micro;

/**
 * Immutable HTTP Request abstraction.
 *
 * Encapsulates method, URI, path, headers, query parameters,
 * route parameters, raw body, parsed JSON body, server parameters, and custom attributes.
 */
class Request
{
    private string $method;
    private string $uri;
    private string $path;
    /** @var array<string, string> */
    private array $headers = [];
    /** @var array<string, mixed> */
    private array $queryParams = [];
    /** @var array<string, string> */
    private array $params = [];
    private ?string $rawBody = null;
    private mixed $parsedJson = null;
    private bool $jsonParsed = false;
    /** @var array<string, mixed> */
    private array $server = [];
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $uri Request URI
     * @param array<string, string> $headers HTTP headers
     * @param string|null $rawBody Raw payload
     * @param array<string, mixed> $queryParams Query parameters
     * @param array<string, mixed> $server Server environment variables
     * @param array<string, string> $params Route parameters
     * @param array<string, mixed> $attributes Custom request attributes
     */
    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        ?string $rawBody = null,
        array $queryParams = [],
        array $server = [],
        array $params = [],
        array $attributes = []
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        
        // Normalize headers to lowercase keys for case-insensitive lookup
        foreach ($headers as $key => $value) {
            $this->headers[strtolower((string) $key)] = (string) $value;
        }

        $this->rawBody = $rawBody;
        $this->queryParams = $queryParams;
        $this->server = $server;
        $this->params = $params;
        $this->attributes = $attributes;
    }

    /**
     * Capture request from global PHP environment ($_SERVER, $_GET, $_POST, php://input).
     */
    public static function capture(): self
    {
        $server = $_SERVER;
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $uri = $server['REQUEST_URI'] ?? '/';

        $headers = [];
        if (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            if (is_array($allHeaders)) {
                $headers = $allHeaders;
            }
        } else {
            foreach ($server as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headerName = str_replace('_', '-', strtolower(substr($key, 5)));
                    $headers[$headerName] = (string) $value;
                } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                    $headerName = str_replace('_', '-', strtolower($key));
                    $headers[$headerName] = (string) $value;
                }
            }
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            $rawBody = null;
        }

        return new self(
            method: $method,
            uri: $uri,
            headers: $headers,
            rawBody: $rawBody,
            queryParams: $_GET,
            server: $server
        );
    }

    /**
     * Factory helper to create a mock or synthetic request.
     *
     * @param string $method
     * @param string $uri
     * @param array<string, string> $headers
     * @param string|null $body
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $server
     * @return self
     */
    public static function create(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        ?string $body = null,
        array $queryParams = [],
        array $server = []
    ): self {
        // Parse query params from URI if query string is present and $queryParams is empty
        if (empty($queryParams) && str_contains($uri, '?')) {
            $queryString = parse_url($uri, PHP_URL_QUERY);
            if ($queryString) {
                parse_str($queryString, $queryParams);
            }
        }

        return new self(
            method: $method,
            uri: $uri,
            headers: $headers,
            rawBody: $body,
            queryParams: $queryParams,
            server: $server
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Return a new instance with updated route parameters.
     *
     * @param array<string, string> $params
     */
    public function withParams(array $params): static
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function getBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * Retrieve parsed JSON body or a specific key.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->jsonParsed) {
            $this->jsonParsed = true;
            if ($this->rawBody !== null && $this->rawBody !== '') {
                try {
                    $decoded = json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
                    $this->parsedJson = is_array($decoded) ? $decoded : null;
                } catch (\JsonException) {
                    $this->parsedJson = null;
                }
            } else {
                $this->parsedJson = null;
            }
        }

        if ($key === null) {
            return $this->parsedJson;
        }

        if (is_array($this->parsedJson)) {
            return $this->parsedJson[$key] ?? $default;
        }

        return $default;
    }

    public function getParsedBody(): mixed
    {
        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->server;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function getIp(): ?string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['HTTP_CLIENT_IP']
            ?? $this->server['REMOTE_ADDR']
            ?? null;
    }

    public function isJson(): bool
    {
        $contentType = $this->getHeader('content-type', '');
        return str_contains(strtolower($contentType), 'application/json');
    }
}
