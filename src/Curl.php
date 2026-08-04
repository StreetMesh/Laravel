<?php

namespace StreetMesh\Protocol;

/**
 * The default way out, using what PHP already has.
 *
 * Deliberately unambitious. A host with its own HTTP client — retries, caching,
 * connection pooling, a circuit breaker — should implement Network over that
 * instead and never use this. It exists so the package works out of the box,
 * not so it works well under load.
 */
final class Curl implements Network
{
    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly int $maximumBytes = 1_048_576,
    ) {}

    public function get(string $url): ?string
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*'],
            CURLOPT_USERAGENT => 'streetmesh-protocol-php',

            /*
             * A resolver that follows a redirect off the host it asked is a
             * resolver that can be pointed at somebody else's answer, and every
             * caller here is deciding who somebody is.
             */
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_PROTOCOLS_STR => 'https',

            // Somebody else's server decides how much it sends; we decide how
            // much we are willing to hold.
            CURLOPT_BUFFERSIZE => 16384,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn ($resource, int $expected, int $received): int => $received > $this->maximumBytes ? 1 : 0,
        ]);

        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if (! is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }

        return $body;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return ['status' => 0, 'body' => ''];
        }

        $lines = ['Content-Type: application/json'];

        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_USERAGENT => 'streetmesh-protocol-php',

            /*
             * No redirects at all, where `get` allows three. A redirect on a
             * write is a request to send this somewhere other than where it was
             * addressed, and an operation is signed for a subject rather than
             * for a destination — it would be replayed perfectly happily
             * wherever it landed.
             */
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'https',
        ]);

        $answer = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        return [
            'status' => is_int($status) ? $status : 0,
            'body' => is_string($answer) ? $answer : '',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record): string => trim((string) ($record['txt'] ?? '')),
            $records,
        )));
    }
}
