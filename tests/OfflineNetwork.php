<?php

namespace StreetMesh\Server\Tests;

use StreetMesh\Protocol\Network;

/**
 * The network, unplugged.
 *
 * Bound by default in this package's TestCase so that no test here can reach a
 * real server, whatever it is configured to talk to. That is not a hypothetical
 * tidiness: a suite that inherited the public PLC directory as a default once
 * published about thirty permanent, global identities for hosts that exist on
 * one laptop. Nothing looked wrong, because the configuration was a URL and a
 * URL that already looks right is not something you go and check.
 *
 * Reads answer "not there", which is the ordinary negative every caller already
 * handles. Writes are accepted and remembered, so a test can ask what would
 * have been sent.
 */
final class OfflineNetwork implements Network
{
    /** @var array<int, array{url: string, body: string}> */
    public array $submitted = [];

    public function get(string $url): ?string
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function txt(string $name): array
    {
        return [];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        $this->submitted[] = ['url' => $url, 'body' => $body];

        return ['status' => 200, 'body' => ''];
    }
}
