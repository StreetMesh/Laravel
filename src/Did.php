<?php

namespace StreetMesh\Protocol;

use InvalidArgumentException;
use Stringable;

/**
 * A `did:web` identifier, and the URL it resolves to.
 *
 * The method is intentionally boring: the identifier *is* the location. Take
 * `did:web:`, swap the colons after the host for slashes, add `did.json`, and
 * you have the document. No registry, no ledger, nothing to ask permission of —
 * which is precisely the property the Protocol introduction praises DIDs for.
 *
 *   did:web:chess.test              → https://chess.test/.well-known/did.json
 *   did:web:alice.apartments.test   → https://alice.apartments.test/.well-known/did.json
 *   did:web:apartments.test:%40alice → https://apartments.test/@alice/did.json
 *
 * Note what that costs, because it is the finding this spike exists to produce:
 * an identifier that encodes where you live cannot survive you moving. See
 * SPIKE-DID.md.
 */
final class Did implements Stringable
{
    /**
     * @param  array<int, string>  $segments  decoded path segments, no slashes
     */
    private function __construct(
        private readonly string $host,
        private readonly array $segments,
    ) {}

    public static function forHost(string $host): self
    {
        return new self(strtolower($host), []);
    }

    /**
     * The DID for a subject that lives at a URL.
     *
     * Both addressing shapes land somewhere legal, but they land in different
     * places: a subject with a host of their own gets a bare-host DID, and one
     * living under a shared host gets a path segment. The DID is therefore not
     * merely derived from a name — it is derived from how the server that
     * issued it chose to arrange things, which is the reason did:plc exists.
     */
    public static function forSubject(string $baseUrl): self
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        if ($host === '') {
            throw new InvalidArgumentException("[{$baseUrl}] names no host.");
        }

        $path = trim((string) parse_url($baseUrl, PHP_URL_PATH), '/');

        return new self($host, $path === '' ? [] : explode('/', $path));
    }

    public static function parse(string $did): self
    {
        if (! str_starts_with($did, 'did:web:')) {
            throw new InvalidArgumentException("[{$did}] is not a did:web identifier.");
        }

        $parts = explode(':', substr($did, strlen('did:web:')));
        $host = rawurldecode(array_shift($parts));

        if ($host === '') {
            throw new InvalidArgumentException("[{$did}] names no host.");
        }

        return new self(strtolower($host), array_map(rawurldecode(...), $parts));
    }

    /**
     * Where the DID document for this identifier lives.
     */
    public function documentUrl(): string
    {
        if ($this->segments === []) {
            return 'https://'.$this->host.'/.well-known/did.json';
        }

        return 'https://'.$this->host.'/'.implode('/', $this->segments).'/did.json';
    }

    /**
     * The subject's own page, which under did:web is the document's parent.
     *
     * Worth noticing: this is the `home` field our discovery document had to
     * add by hand, and here it falls out of the identifier for nothing.
     */
    public function subjectUrl(): string
    {
        return 'https://'.$this->host.($this->segments === [] ? '' : '/'.implode('/', $this->segments));
    }

    public function fragment(string $name): string
    {
        return (string) $this.'#'.$name;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function __toString(): string
    {
        /*
         * A path segment may hold letters, digits, `.`, `-` and `_` unescaped
         * and nothing else — so the `@` a path-addressed domicile puts in front
         * of a username has to be percent-encoded. A host needs nothing done to
         * it except a port, whose colon would otherwise end the segment.
         */
        return 'did:web:'.implode(':', [
            str_replace(':', '%3A', $this->host),
            ...array_map(rawurlencode(...), $this->segments),
        ]);
    }
}
