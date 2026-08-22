<?php

namespace StreetMesh\Server\Domicile\Residents;

use InvalidArgumentException;

/**
 * The name somebody is known by, everywhere.
 *
 * A handle is a hostname — alice.games.test — and that is not a formatting
 * detail. It is what a venue types into DNS, what appears in every record this
 * person ever signs, and what a stranger reads years later to work out who
 * wrote something. It cannot be validated loosely and fixed afterwards: by the
 * time it is wrong, other servers are holding it.
 *
 * Two parts, kept apart on purpose. A resident chooses the label; the server
 * supplies the host and never lets anybody else decide it. Handed one string,
 * somebody would eventually be able to type a whole hostname into the box and
 * claim a name this server has no business issuing.
 */
final readonly class Handle
{
    /**
     * The longest a DNS label may be.
     */
    private const LIMIT = 63;

    /**
     * Names this server needs for itself.
     *
     * A resident holding one of these would shadow something the server has to
     * be able to serve — and because a handle is a hostname, the collision is
     * real rather than cosmetic.
     */
    private const KEPT = [
        'www', 'api', 'xrpc', 'admin', 'mail', 'smtp', 'imap', 'ns', 'ns1', 'ns2',
        'hub', 'realtime', 'static', 'assets', 'cdn', 'localhost', 'well-known',
    ];

    private function __construct(
        public string $label,
        public string $host,
    ) {}

    public static function for(string $label, string $host): self
    {
        $label = strtolower(trim($label));

        if ($label === '') {
            throw new InvalidArgumentException('An address needs a name in front of it.');
        }

        if (strlen($label) > self::LIMIT) {
            throw new InvalidArgumentException('That name is too long to be part of an address.');
        }

        /*
         * Letters, digits and hyphens, not starting or ending with one. This is
         * the hostname rule rather than a house style — a label outside it is
         * one DNS will not carry, so it would be a name that resolves nowhere.
         */
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
            throw new InvalidArgumentException(
                'An address can use letters, numbers and hyphens, and has to start and end with a letter or number.'
            );
        }

        if (in_array($label, self::KEPT, true)) {
            throw new InvalidArgumentException('That name is kept for the server itself.');
        }

        return new self($label, strtolower(trim($host)));
    }

    public function __toString(): string
    {
        return $this->label.'.'.$this->host;
    }
}
