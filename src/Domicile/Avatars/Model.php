<?php

namespace StreetMesh\Server\Domicile\Avatars;

use RuntimeException;
use StreetMesh\Protocol\Glb;

/**
 * The body somebody wears, in the one shape this server will keep.
 *
 * The other half of an avatar, and deliberately a weaker promise than the
 * half beside it. `Icon` is safe because it re-encodes: whatever arrived is
 * demonstrably a picture, because it survived being decoded and drawn again,
 * and anything hiding in it did not survive the round trip. There is no glTF
 * writer in PHP, so nothing here can make that promise, and pretending
 * otherwise by validating harder would be the more dangerous mistake.
 *
 * So this checks what can honestly be checked — that the file is a well-formed
 * glTF binary that agrees with its own arithmetic, and that it is an avatar
 * rather than any model at all — and stops. What survives is still somebody's
 * file, and everything downstream treats it that way.
 *
 * The rest of the defence is elsewhere, and is unchanged by any of this. These
 * bytes are never executed and never rendered as a document: they are served
 * with their own type, `nosniff`, and a content policy that permits nothing,
 * exactly as a picture is. That is what settles the risk `Icon` was written
 * against — an origin that also answers for somebody's identity being talked
 * into running something.
 *
 * What it does not settle is a model that is merely enormous: a decompression
 * bomb, eight-thousand-pixel textures, a million triangles. That is a budget
 * rather than a check, it belongs to whoever is about to draw the thing, and
 * it is not decided here. The one blunt limit that does apply is the byte
 * ceiling in `streetmesh.blobs.limits`, enforced by `BlobStore` where every
 * other kind of bytes is measured, rather than restated here.
 */
final class Model
{
    private function __construct(public readonly string $bytes) {}

    /**
     * Take what arrived and give back what will be stored.
     *
     * Throws with something a person can act on, because this is reached from
     * a screen somebody is looking at and from a venue that has to tell them
     * why their face was not accepted.
     */
    public static function from(string $uploaded): self
    {
        $glb = Glb::inspect($uploaded);

        /*
         * An avatar rather than a prop.
         *
         * `/avatar` is the address a spatial place fetches to put somebody in a
         * body, so a model with no humanoid rig in it is not a smaller version
         * of the right answer — it is the wrong kind of thing, and every caller
         * would have to discover that for itself.
         */
        if (! $glb->isVrm()) {
            throw new RuntimeException(
                'That model is not an avatar: it does not carry '.Glb::VRM_EXTENSION.'.'
            );
        }

        return new self($uploaded);
    }
}
