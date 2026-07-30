<?php

namespace StreetMesh\Protocol;


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
     * The operation that brings an identity into being.
     *
     * Rotation keys are the ones that can later change the record — including
     * replacing the signing key — so they are the thing a person actually has
     * to keep. The signing key can be held by the server they live on; a
     * rotation key held only there means moving out is at that server's
     * discretion, which rather defeats the point.
     *
     * @param  array<int, P256>  $rotationKeys  highest authority first
     * @return array<string, mixed> the signed operation
     */
    public static function genesis(
        array $rotationKeys,
        P256 $signingKey,
        string $handle,
        string $serviceEndpoint,
    ): array {
        $operation = [
            'type' => 'plc_operation',
            'rotationKeys' => array_map(fn (P256 $key): string => $key->didKey(), $rotationKeys),
            'verificationMethods' => ['atproto' => $signingKey->didKey()],
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
