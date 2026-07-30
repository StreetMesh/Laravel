<?php

namespace StreetMesh\Protocol;

use RuntimeException;

/**
 * The name a person actually types, and how it finds the identity behind it.
 *
 * A DID is unreadable on purpose — `did:plc:45vsx3mflmaiw5ksoo73q6ff` is stable
 * precisely because it means nothing. The handle is the readable alias, and this
 * is the mechanism that keeps the DID off the screen.
 *
 * Both directions matter. A handle pointing at a DID proves the server hosting
 * the name says so; a document claiming the handle back proves the identity
 * says so. Either alone lets somebody hang a familiar name on a stranger's
 * identity, so `verify()` is the method to reach for and `resolve()` is the half
 * of it you should use only when you already know why.
 */
final class Handle
{
    public function __construct(private readonly Network $network = new Curl) {}

    /**
     * Which identity a name points at, according to whoever serves the name.
     *
     * A handle is a domain name. That is a constraint rather than a formatting
     * preference: a server that puts its subjects on paths has no hostname to
     * give them, and therefore cannot offer them handles at all without
     * publishing a DNS record for each one.
     */
    public function resolve(string $handle): string
    {
        $handle = self::normalize($handle);

        return $this->fromWellKnown($handle)
            ?? $this->fromDns($handle)
            ?? throw new RuntimeException("[{$handle}] does not resolve to an identity.");
    }

    /**
     * Resolve, and check the identity agrees it answers to this name.
     *
     * @param  callable(string): array<string, mixed>  $document  resolves a DID
     */
    public function verify(string $handle, callable $document): string
    {
        $handle = self::normalize($handle);
        $did = $this->resolve($handle);

        $claimed = $document($did)['alsoKnownAs'] ?? [];

        if (! in_array('at://'.$handle, $claimed, strict: true)) {
            throw new RuntimeException(
                "[{$handle}] points at [{$did}], but that identity does not answer to it."
            );
        }

        return $did;
    }

    /**
     * Preferred over DNS because it needs no zone access — a server that can
     * serve a host can publish this, which is not true of a TXT record.
     */
    private function fromWellKnown(string $handle): ?string
    {
        $body = $this->network->get("https://{$handle}/.well-known/atproto-did");

        if ($body === null) {
            return null;
        }

        $did = trim($body);

        return str_starts_with($did, 'did:') ? $did : null;
    }

    /**
     * The escape hatch for a server that puts subjects on paths: the handle
     * still has to be a hostname, but nothing has to be served there — only
     * named in DNS.
     */
    private function fromDns(string $handle): ?string
    {
        foreach ($this->network->txt('_atproto.'.$handle) as $record) {
            if (str_starts_with($record, 'did=')) {
                return substr($record, 4);
            }
        }

        return null;
    }

    private static function normalize(string $handle): string
    {
        return strtolower(ltrim(trim($handle), '@'));
    }
}
