<?php

declare(strict_types=1);

namespace EidCloud\Micro;

/**
 * HTTP Response abstraction with fluent JSON, headers, and status code manipulation.
 */
class Response
{
    private int $statusCode;
    /** @var array<string, string> */
    private array $headers = [];
    private string $body;

    /**
     * @param int $statusCode HTTP response status code
     * @param array<string, string> $headers Key-value array of HTTP headers
     * @param string $body Raw response body content
     */
    public function __construct(int $statusCode = 200, array $headers = [], string $body = '')
    {
        $this->statusCode = $statusCode;
        $this->body = $body;

        foreach ($headers as $name => $value) {
            $this->setHeader($name, (string) $value);
        }
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data Data to be JSON-encoded
     * @param int $statusCode HTTP status code (default 200)
     * @param array<string, string> $headers Additional headers
     */
    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers['Content-Type'] = 'application/json; charset=utf-8';

        return new self($statusCode, $headers, $encoded);
    }

    /**
     * Create a plain text response.
     *
     * @param string $text Plain text content
     * @param int $statusCode HTTP status code (default 200)
     * @param array<string, string> $headers Additional headers
     */
    public static function text(string $text, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/plain; charset=utf-8';
        return new self($statusCode, $headers, $text);
    }

    /**
     * Create an HTML response.
     *
     * @param string $html HTML string content
     * @param int $statusCode HTTP status code (default 200)
     * @param array<string, string> $headers Additional headers
     */
    public static function html(string $html, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($statusCode, $headers, $html);
    }

    /**
     * Create an empty response (e.g. 204 No Content).
     *
     * @param int $statusCode HTTP status code (default 204)
     * @param array<string, string> $headers Additional headers
     */
    public static function empty(int $statusCode = 204, array $headers = []): self
    {
        return new self($statusCode, $headers, '');
    }

    /**
     * Create a redirect response.
     *
     * @param string $url Target redirect destination
     * @param int $statusCode Redirect status code (301, 302, 307, 308)
     * @param array<string, string> $headers Additional headers
     */
    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self
    {
        $headers['Location'] = $url;
        return new self($statusCode, $headers, '');
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $lookup = strtolower($name);
        foreach ($this->headers as $headerName => $headerValue) {
            if (strtolower($headerName) === $lookup) {
                return $headerValue;
            }
        }
        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function withStatus(int $statusCode): static
    {
        $clone = clone $this;
        $clone->statusCode = $statusCode;
        return $clone;
    }

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->setHeader($name, $value);
        return $clone;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->setHeader($name, (string) $value);
        }
        return $clone;
    }

    public function withBody(string $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function withJson(mixed $data): static
    {
        $clone = clone $this;
        $clone->body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $clone->setHeader('Content-Type', 'application/json; charset=utf-8');
        return $clone;
    }

    private function setHeader(string $name, string $value): void
    {
        $lookup = strtolower($name);
        // Replace existing case variant if already present
        foreach (array_keys($this->headers) as $existingName) {
            if (strtolower((string) $existingName) === $lookup) {
                unset($this->headers[$existingName]);
            }
        }
        $this->headers[$name] = $value;
    }

    /**
     * Output response headers, HTTP status code, and response body.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}", true);
            }
        }

        echo $this->body;
    }
}
