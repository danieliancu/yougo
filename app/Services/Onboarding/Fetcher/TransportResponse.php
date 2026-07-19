<?php

namespace App\Services\Onboarding\Fetcher;

final readonly class TransportResponse
{
    /**
     * @param  array<string, string>  $headers  lower-cased header names => first value
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public string $effectiveUrl,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
