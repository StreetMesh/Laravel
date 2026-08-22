<?php

namespace StreetMesh\Server\Protocol\Identity;

/**
 * What a server publishes about an identity so a stranger can check its work.
 *
 * The whole of first contact. Somebody holding a signed document knows only
 * which key signed it; this is where they find out what that key is, without
 * being introduced and without asking anybody's permission.
 *
 * Shaped after the documents ATProtocol publishes, down to the field names, so
 * that software which has never heard of StreetMesh can still read one.
 */
final class DidDocument
{
    private const CONTEXT = [
        'https://www.w3.org/ns/did/v1',
        'https://w3id.org/security/multikey/v1',
    ];

    /**
     * @param  string|array<int, string>  $serviceType  what this server does
     * @return array<string, mixed>
     */
    public static function for(Identity $identity, string $serviceEndpoint, string|array $serviceType): array
    {
        $services = [];

        foreach ((array) $serviceType as $name => $type) {
            $services[] = [
                // The first keeps ATProtocol's name so their software finds it;
                // anything further is named for the capability rather than for
                // its type, which reads better and stays stable if a type is
                // renamed.
                'id' => count($services) === 0 ? '#atproto_pds' : '#streetmesh_'.$name,
                'type' => $type,
                'serviceEndpoint' => $serviceEndpoint,
            ];
        }

        $document = [
            '@context' => self::CONTEXT,
            'id' => $identity->did,

            'verificationMethod' => [[
                'id' => $identity->keyId(),
                'type' => 'Multikey',
                'controller' => $identity->did,
                'publicKeyMultibase' => $identity->key()->multikey(),
            ]],

            'assertionMethod' => [$identity->keyId()],

            'service' => $services,
        ];

        if ($identity->handle !== null) {
            /*
             * Every other name this identity answers to. Half of what makes a
             * handle trustworthy: a name pointing at an identity proves only
             * that whoever serves the name says so, and this is the identity
             * saying it back. Without both, anybody able to publish a name could
             * hang it on a stranger.
             */
            $document['alsoKnownAs'] = ['at://'.$identity->handle];
        }

        return $document;
    }
}
