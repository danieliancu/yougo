<?php

namespace App\Services\Onboarding\Fetcher;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * The real transport implementation. The only class in the onboarding fetcher stack
 * that ever touches Laravel's Http facade / Guzzle / curl directly. Translates the
 * `pinned_ip` option into curl's CURLOPT_RESOLVE, which is what actually pins the TCP
 * connection to the IP address HttpWebsiteSourceFetcher already validated a moment
 * earlier — closing the gap between "we checked this IP is safe" and "this is the IP
 * we actually connect to".
 */
class GuzzleOnboardingHttpTransport implements OnboardingHttpTransport
{
    public function send(string $method, string $url, array $options = []): TransportResponse
    {
        if (strtoupper($method) !== 'GET') {
            throw new InvalidArgumentException('GuzzleOnboardingHttpTransport only supports GET requests.');
        }

        $pending = Http::withHeaders($options['headers'] ?? [])
            ->connectTimeout($options['connect_timeout'] ?? 5)
            ->timeout($options['timeout'] ?? 12)
            ->withOptions([
                'allow_redirects' => false,
            ]);

        if (isset($options['verify'])) {
            $pending = $pending->withOptions(['verify' => $options['verify']]);
        }

        if (! empty($options['pinned_ip'])) {
            $pending = $pending->withOptions([
                'curl' => [
                    CURLOPT_RESOLVE => [$this->resolveEntry($url, $options['pinned_ip'])],
                ],
            ]);
        }

        $response = $pending->send('GET', $url);

        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? ($values[0] ?? '') : (string) $values;
        }

        return new TransportResponse(
            statusCode: $response->status(),
            headers: $headers,
            body: $response->body(),
            effectiveUrl: $url,
        );
    }

    private function resolveEntry(string $url, string $ip): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $scheme = $parts['scheme'] ?? 'https';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        // curl's --resolve/CURLOPT_RESOLVE syntax requires IPv6 literals in brackets.
        $ip = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return "{$host}:{$port}:{$ip}";
    }
}
