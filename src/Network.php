<?php

namespace StreetMesh\Protocol;

/**
 * The only way anything here reaches the outside world.
 *
 * Resolving an identity means asking somebody else's server, so a handful of
 * these classes genuinely need the network — and a package that reaches for a
 * global HTTP client is a package that cannot be tested without one, and cannot
 * be dropped into a host that has its own.
 *
 * So it is one narrow interface, passed in. Two methods, because identity needs
 * exactly two things: fetch a document, and read a DNS TXT record.
 */
interface Network
{
    /**
     * The body at a URL, or null if there isn't one to be had.
     *
     * Null rather than an exception for any ordinary failure — unreachable,
     * 404, a redirect too far. Callers here are all asking "is it there?" and
     * have a fallback ready, so an absent answer is a normal outcome rather
     * than an exceptional one.
     */
    public function get(string $url): ?string;

    /**
     * The TXT records at a name, in no particular order.
     *
     * @return array<int, string>
     */
    public function txt(string $name): array;
}
