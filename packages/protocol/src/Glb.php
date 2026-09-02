<?php

namespace StreetMesh\Protocol;

use JsonException;
use RuntimeException;

/**
 * A glTF binary, read far enough to know it is one.
 *
 * The container every avatar model arrives in. VRM is not a separate format —
 * a `.vrm` is a GLB whose JSON names `VRMC_vrm` among its extensions — so
 * there is one reader here rather than two, and asking whether a model is an
 * avatar is a question about its contents rather than about its file name.
 *
 * This exists because whether `finfo` knows what a GLB is depends on the
 * libmagic an operator's PHP happened to be built against. Newer ones answer
 * `model/gltf-binary`; older ones say `application/octet-stream`, which is the
 * name for "no idea". A network where a resident may keep a body on one server
 * and not on another, for a reason neither operator can see, is not one worth
 * shipping — and the header is twelve bytes and unambiguous, so reading it is
 * cheaper than depending on the answer.
 *
 * What this is not: a validator that makes the contents safe. It reads the
 * envelope — magic, version, the lengths agreeing with each other, the JSON
 * parsing — and stops. A well-formed GLB can still describe a million
 * triangles. Everything that survives this is still somebody's file.
 */
final class Glb
{
    /**
     * What these bytes are called, once they are known to be these bytes.
     *
     * The name glTF registered with IANA, and the one the avatar lexicon
     * accepts. Not `application/octet-stream`, which is what a sniffer says
     * when it has given up.
     */
    public const MIME = 'model/gltf-binary';

    /** The extension that makes a model an avatar rather than a prop. */
    public const VRM_EXTENSION = 'VRMC_vrm';

    private const MAGIC = 'glTF';

    private const VERSION = 2;

    /** Magic, version, total length: three little-endian words. */
    private const HEADER = 12;

    /** Length and type, per chunk. */
    private const CHUNK_HEADER = 8;

    private const JSON_CHUNK = 0x4E4F534A;

    /**
     * How deep the JSON may nest before it is somebody being clever.
     *
     * A scene graph is wide rather than deep, so the real ceiling is far below
     * this. It is here because `json_decode` defaults to 512 and a parser
     * recursing 512 deep on a hostile file is work nobody asked for.
     */
    private const DEPTH = 64;

    /**
     * @param  array<string, mixed>  $json  the decoded JSON chunk
     */
    private function __construct(public readonly array $json) {}

    /**
     * Do these bytes claim to be a GLB at all?
     *
     * Deliberately shallow, and separate from reading one. This is the question
     * a sniffer asks — *what kind of thing is this* — and answering it must not
     * depend on the file being any good, or bytes that fail a later check would
     * be reported as an unknown type rather than as a broken model, and the
     * refusal would name the wrong problem.
     */
    public static function looksLikeOne(string $bytes): bool
    {
        return strlen($bytes) >= self::HEADER && str_starts_with($bytes, self::MAGIC);
    }

    /**
     * Read the envelope, or refuse with a reason.
     *
     * Every check here is against the file's own arithmetic: the length it
     * declares against the length it has, and the JSON chunk against the room
     * it was given. A file that disagrees with itself is the shape truncation
     * and padding both take, and neither is something to hand on.
     *
     * The chunk after the JSON one holds the vertices and is not walked. It
     * cannot be truncated without the total length having already been wrong,
     * and beyond that its contents are the renderer's business rather than
     * this server's -- which is the same line `Model` draws, for the same
     * reason.
     */
    public static function inspect(string $bytes): self
    {
        $total = strlen($bytes);

        if (! self::looksLikeOne($bytes)) {
            throw new RuntimeException('That is not a glTF binary.');
        }

        /** @var array{version: int, length: int} $header */
        $header = unpack('Vversion/Vlength', substr($bytes, 4, 8));

        if ($header['version'] !== self::VERSION) {
            throw new RuntimeException(
                'That is a version '.$header['version'].' glTF binary, and this reads version '.self::VERSION.'.'
            );
        }

        /*
         * The declared length is the whole file including this header. Bytes
         * after it are not part of the model, and a reader that ignored them
         * would be a reader that can be handed a model with something else
         * stapled to the end.
         */
        if ($header['length'] !== $total) {
            throw new RuntimeException(
                'That file says it is '.$header['length'].' bytes and it is '.$total.'.'
            );
        }

        return new self(self::readJsonChunk($bytes, $total));
    }

    /**
     * Every extension the file says it uses.
     *
     * `extensionsUsed` rather than the extension objects themselves, because
     * the list is the file's own declaration of what a reader has to
     * understand, and a file relying on something it did not declare is
     * already broken by its own spec.
     *
     * @return array<int, string>
     */
    public function extensionsUsed(): array
    {
        $used = $this->json['extensionsUsed'] ?? [];

        return is_array($used)
            ? array_values(array_filter($used, is_string(...)))
            : [];
    }

    public function isVrm(): bool
    {
        return in_array(self::VRM_EXTENSION, $this->extensionsUsed(), strict: true);
    }

    /**
     * The first chunk, which the specification requires to be the JSON one.
     *
     * Required rather than searched for: a file whose structure is described
     * somewhere other than where it must be is a file this does not have to
     * accommodate.
     *
     * @return array<string, mixed>
     */
    private static function readJsonChunk(string $bytes, int $total): array
    {
        if ($total < self::HEADER + self::CHUNK_HEADER) {
            throw new RuntimeException('That glTF binary has no chunks in it.');
        }

        /** @var array{length: int, type: int} $chunk */
        $chunk = unpack('Vlength/Vtype', substr($bytes, self::HEADER, self::CHUNK_HEADER));

        if ($chunk['type'] !== self::JSON_CHUNK) {
            throw new RuntimeException('That glTF binary does not begin with its JSON.');
        }

        $start = self::HEADER + self::CHUNK_HEADER;

        /*
         * Does the JSON fit in what is left? `$total` is at least `$start` by
         * the guard above, so the remainder cannot go negative, and a length
         * unpacked from four bytes cannot overflow a PHP integer -- so this
         * comparison means what it reads as, which is not true of the same line
         * in a language with 32-bit arithmetic.
         */
        if ($chunk['length'] > $total - $start) {
            throw new RuntimeException('That glTF binary describes more JSON than it contains.');
        }

        try {
            $json = json_decode(
                substr($bytes, $start, $chunk['length']),
                associative: true,
                depth: self::DEPTH,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $unreadable) {
            throw new RuntimeException('That glTF binary\'s description could not be read: '.$unreadable->getMessage());
        }

        if (! is_array($json)) {
            throw new RuntimeException('That glTF binary\'s description is not an object.');
        }

        return $json;
    }
}
