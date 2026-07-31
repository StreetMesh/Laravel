<?php

namespace StreetMesh\Protocol;

/**
 * Something that can sign, without the caller having to know what it is.
 *
 * Three curves are in use and the choice is not free: `did:plc` permits only
 * secp256k1 and P-256, while anything internal can use Ed25519, which is nicer
 * and deterministic. An identity therefore has to be able to sign with whichever
 * curve its method allows, and everything that signs on its behalf — an
 * attestation, a commit — has to accept that rather than insist.
 *
 * Verification is deliberately not here. Signing needs a private key and a
 * deliberate choice; checking needs neither and must accept anything the network
 * produces, which is what Signature does.
 */
interface SigningKey
{
    /**
     * Sign bytes exactly as given.
     *
     * There is no method taking a structure, on purpose. Signing a structure
     * means the verifier rebuilds the bytes from whatever it decoded, and
     * everything in between gets a vote.
     */
    public function sign(string $message): string;

    /**
     * The public half, as the DID world writes keys — curve included, because a
     * verifier cannot check a signature without knowing which curve it is on.
     */
    public function multikey(): string;

    public function curve(): string;

    /**
     * How JOSE names this curve's signatures.
     */
    public function algorithm(): string;
}
