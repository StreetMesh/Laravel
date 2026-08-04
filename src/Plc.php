<?php

namespace StreetMesh\Protocol;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * `did:plc` — an identifier that is not an address.
 *
 * The DID is the hash of the operation that created it, so it carries no
 * information about where its subject lives and survives them moving. That is
 * the property `did:web` cannot have and the reason ATProtocol defaults to this
 * despite the method calling itself a placeholder.
 *
 * What the directory is trusted for is worth being exact about, because it
 * decides whether this is acceptable at all: the identifier is derived from a
 * signed genesis operation, and every later operation must be signed by a key
 * the subject holds. The directory therefore cannot forge an identity, invent
 * one, or reassign one — it can only decline to answer. Availability, not
 * authenticity.
 */
final class Plc
{
    public const DIRECTORY = 'https://plc.directory';

    /**
     * The DID an operation creates, derived rather than assigned.
     *
     * @param  array<string, mixed>  $operation  the signed genesis operation
     */
    public static function did(array $operation): string
    {
        $hash = hash('sha256', DagCbor::encode($operation), binary: true);

        return 'did:plc:'.substr(strtolower(self::base32($hash)), 0, 24);
    }

    /**
     * Every key an identity has used for one purpose, and when each was current.
     *
     * A DID document publishes the key that is current now, which is the wrong
     * question to ask of a signature made earlier. Keys are rotated — after a
     * compromise, on moving to another server, or as ordinary hygiene — and a
     * signature checked against today's key fails for a document that was
     * perfectly good when it was made.
     *
     * This is not hypothetical: real identities have rotated, and nothing they
     * signed beforehand verifies against the key their document publishes now.
     * A system whose whole claim is that records outlive their issuer has to be
     * able to ask what was true then rather than what is true today.
     *
     * The audit log makes that answerable, which is a property `did:web` does
     * not have at all — it publishes a document and no history whatsoever.
     *
     * @param  array<int, array<string, mixed>>  $auditLog  oldest first
     * @param  string  $fragment  the verification method, e.g. `atproto`
     * @return array<int, array{key: string, from: string, until: string|null}>
     */
    public static function keyHistory(array $auditLog, string $fragment = 'atproto'): array
    {
        $rotations = [];

        foreach ($auditLog as $entry) {
            /*
             * Nullified operations were undone by a recovery using a
             * higher-priority rotation key. They stay in the log so the recovery
             * is auditable, and must never be read as history.
             */
            if ($entry['nullified'] ?? false) {
                continue;
            }

            $key = $entry['operation']['verificationMethods'][$fragment] ?? null;

            if (! is_string($key)) {
                continue;
            }

            $key = str_starts_with($key, 'did:key:') ? substr($key, strlen('did:key:')) : $key;

            // An operation that leaves the key alone is not a rotation, and
            // recording one would invent a boundary that never existed.
            if ($rotations !== [] && end($rotations)['key'] === $key) {
                continue;
            }

            $rotations[] = ['key' => $key, 'at' => (string) ($entry['createdAt'] ?? '')];
        }

        // A key is current until the next one replaces it, and the last is
        // current still — which is the only thing a DID document can tell you.
        $history = [];

        foreach ($rotations as $index => $rotation) {
            $history[] = [
                'key' => $rotation['key'],
                'from' => $rotation['at'],
                'until' => $rotations[$index + 1]['at'] ?? null,
            ];
        }

        return $history;
    }

    /**
     * The key that was current at a given moment.
     *
     * Which moment to ask about is the caller's decision and deserves thought.
     * A timestamp inside a signed document is asserted by whoever signed it, so
     * it says when they *claim* to have signed — enough against ordinary
     * rotation, worth nothing against somebody holding a retired key. An anchor
     * asserted by the receiving party when the document arrived is far stronger,
     * which is a reason to record receipt rather than to trust issuance.
     *
     * @param  array<int, array<string, mixed>>  $auditLog  oldest first
     */
    public static function keyAt(
        array $auditLog,
        DateTimeInterface $at,
        string $fragment = 'atproto',
    ): string {
        foreach (self::keyHistory($auditLog, $fragment) as $period) {
            $from = new DateTimeImmutable($period['from']);
            $until = $period['until'] === null ? null : new DateTimeImmutable($period['until']);

            if ($at >= $from && ($until === null || $at < $until)) {
                return $period['key'];
            }
        }

        throw new InvalidArgumentException(
            'That identity published no '.$fragment.' key at '.$at->format(DATE_ATOM).'.'
        );
    }

    /**
     * The operation that brings an identity into being.
     *
     * Rotation keys are the ones that can later change the record — including
     * replacing the signing key — so they are the thing a person actually has
     * to keep. The signing key can be held by the server they live on; a
     * rotation key held only there means moving out is at that server's
     * discretion, which rather defeats the point.
     *
     * @param  array<int, SigningKey>  $rotationKeys  highest authority first
     * @return array<string, mixed> the signed operation
     */
    public static function genesis(
        array $rotationKeys,
        SigningKey $signingKey,
        string $handle,
        string $serviceEndpoint,
    ): array {
        $operation = [
            'type' => 'plc_operation',
            'rotationKeys' => array_map(fn (SigningKey $key): string => 'did:key:'.$key->multikey(), $rotationKeys),
            'verificationMethods' => ['atproto' => 'did:key:'.$signingKey->multikey()],
            'alsoKnownAs' => ['at://'.$handle],
            'services' => [
                'atproto_pds' => [
                    'type' => 'AtprotoPersonalDataServer',
                    'endpoint' => $serviceEndpoint,
                ],
            ],
            'prev' => null,
        ];

        /*
         * Signed over the unsigned operation, then the signature joins it — and
         * the DID is the hash of the result, so an operation and its identifier
         * cannot come apart.
         */
        $signature = $rotationKeys[0]->sign(DagCbor::encode($operation));

        $operation['sig'] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $operation;
    }

    /**
     * Change the name an identity is known by.
     *
     * The operation `did:web` cannot express, and most of the reason for
     * preferring this method. The identifier is the hash of the *genesis*
     * operation, so it does not move — every record signed under the old name
     * stays this person's, and anybody holding one can still resolve it.
     *
     * @param  array<string, mixed>  $previous  the signed operation at the head of the log
     * @return array<string, mixed> the signed operation
     */
    public static function rename(array $previous, SigningKey $rotationKey, string $handle): array
    {
        return self::next($previous, $rotationKey, static function (array $operation) use ($handle): array {
            $operation['alsoKnownAs'] = ['at://'.$handle];

            return $operation;
        });
    }

    /**
     * Move to another server.
     *
     * The other half of what an identifier that is not an address buys you. The
     * subject keeps their DID, their handle and their history; only the place
     * their repository is served from changes.
     *
     * Signed by a rotation key, which is why that key must not live only on the
     * server being left — a move at the discretion of the server you are
     * leaving is not a move.
     *
     * @param  array<string, mixed>  $previous  the signed operation at the head of the log
     * @return array<string, mixed> the signed operation
     */
    public static function moveTo(array $previous, SigningKey $rotationKey, string $serviceEndpoint): array
    {
        return self::next($previous, $rotationKey, static function (array $operation) use ($serviceEndpoint): array {
            $operation['services']['atproto_pds']['endpoint'] = $serviceEndpoint;

            return $operation;
        });
    }

    /**
     * Replace the key an identity signs with.
     *
     * Ordinary hygiene, or the first thing to do after a compromise. Note what
     * does *not* happen: earlier signatures go on verifying, because the audit
     * log says which key was current when — see `keyAt`.
     *
     * @param  array<string, mixed>  $previous  the signed operation at the head of the log
     * @return array<string, mixed> the signed operation
     */
    public static function rekey(array $previous, SigningKey $rotationKey, SigningKey $signingKey): array
    {
        return self::next($previous, $rotationKey, static function (array $operation) use ($signingKey): array {
            $operation['verificationMethods']['atproto'] = 'did:key:'.$signingKey->multikey();

            return $operation;
        });
    }

    /**
     * The next operation in a log, however it differs from the last one.
     *
     * `prev` is the CID of the previous operation *including its signature*,
     * which is what makes the log a chain rather than a pile: an operation
     * names exactly the state it was written against, so two conflicting
     * updates cannot both be applied and the directory can say which it holds.
     *
     * @param  array<string, mixed>  $previous
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function next(array $previous, SigningKey $rotationKey, callable $change): array
    {
        if (! isset($previous['sig'])) {
            throw new InvalidArgumentException(
                'That operation has no signature, so it is not one the directory can be holding.'
            );
        }

        $prev = (string) Cid::forRecord($previous);

        // Everything the last operation said, less its signature — which
        // belongs to that operation and never travels forward.
        unset($previous['sig']);

        $operation = $change($previous);
        $operation['prev'] = $prev;

        $signature = $rotationKey->sign(DagCbor::encode($operation));

        $operation['sig'] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $operation;
    }

    /**
     * RFC 4648 base32 without padding, which PHP has no function for.
     */
    private static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        $encoded = '';
        $buffer = 0;
        $pending = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $pending += 8;

            while ($pending >= 5) {
                $pending -= 5;
                $encoded .= $alphabet[($buffer >> $pending) & 31];
            }
        }

        if ($pending > 0) {
            $encoded .= $alphabet[($buffer << (5 - $pending)) & 31];
        }

        return $encoded;
    }
}
