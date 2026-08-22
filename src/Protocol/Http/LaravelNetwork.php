<?php

namespace StreetMesh\Server\Protocol\Http;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use StreetMesh\Protocol\Network;
use Throwable;

/**
 * The way out, using the application's own HTTP client.
 *
 * This is the binding the package exists for. The framework-free layer defines
 * what it needs from the network in two methods and ships a plain cURL
 * implementation so it works alone; here that is replaced by something with the
 * host's timeouts, its retries, its logging, and — the part that matters most —
 * its cache.
 *
 * Identity documents are fetched constantly and change rarely, so caching them
 * is the difference between resolving a handle costing nothing and costing a
 * round trip to somebody else's server on every page load. The interval is
 * short because a DID document is how a key rotation becomes visible, and a
 * stale one is a key that was retired an hour ago and is still being trusted.
 */
final class LaravelNetwork implements Network
{
    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly int $cacheSeconds = 300,
    ) {}

    public function get(string $url): ?string
    {
        return Cache::remember('streetmesh:fetch:'.sha1($url), $this->cacheSeconds, function () use ($url): ?string {
            try {
                $response = Http::acceptJson()
                    ->timeout($this->timeoutSeconds)
                    ->withUserAgent('streetmesh-protocol-laravel')
                    ->get($url);
            } catch (Throwable) {
                /*
                 * Null rather than an exception, because every caller here is
                 * asking "is it there?" with a fallback ready. A domicile that
                 * is down should make a handle unresolvable, not make the page
                 * that mentioned it fail.
                 */
                return null;
            }

            return $response->successful() ? $response->body() : null;
        });
    }

    /**
     * Submit a body, and hand back what came out.
     *
     * Not cached, and never null. Everything else here asks a question with a
     * useful negative answer — "is it there?" has a fallback ready. This one
     * changes something on somebody else's server, and an operation that was
     * refused and one that was never sent leave the caller in different places,
     * only one of which is safe to retry. So the status and the body come back
     * intact and the caller decides what they mean.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders($headers)
                ->timeout($this->timeoutSeconds)
                ->withUserAgent('streetmesh-protocol-laravel')
                ->post($url);
        } catch (Throwable $failed) {
            /*
             * Status 0 says "this never reached anybody", which is the one
             * outcome that is safe to try again. A refusal carries a real
             * status and must not be confused with it.
             */
            return ['status' => 0, 'body' => $failed->getMessage()];
        }

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * @return array<int, string>
     */
    public function txt(string $name): array
    {
        return Cache::remember('streetmesh:txt:'.sha1($name), $this->cacheSeconds, function () use ($name): array {
            $records = @dns_get_record($name, DNS_TXT) ?: [];

            return array_values(array_filter(array_map(
                fn (array $record): string => trim((string) ($record['txt'] ?? '')),
                $records,
            )));
        });
    }
}
