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
 * So it is one narrow interface, passed in. Three methods: fetch a document,
 * read a DNS TXT record, and — only since identities became `did:plc` — submit
 * a signed operation to a directory.
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

    /**
     * Submit a body to a URL, and hand back what came out.
     *
     * The one method here that changes something rather than asking about it,
     * and the difference matters to the caller: `get` answers null for any
     * ordinary failure because "is it there?" has a useful negative answer.
     * Submitting has no useful negative answer — an operation that was refused
     * and one that was never sent leave the caller in different places, and
     * only one of them is safe to retry.
     *
     * So a refusal comes back as the body of the refusal, with the status, and
     * deciding what it means is the caller's job.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers = []): array;
}
