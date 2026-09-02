<?php

namespace StreetMesh\Protocol\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreetMesh\Protocol\Glb;

/**
 * Reading a model far enough to know it is one.
 *
 * `finfo` does not know glTF and answers `application/octet-stream` for a
 * model, which is the name for "no idea" — so a store that decides what it
 * holds by looking at bytes has nothing to act on. Twelve bytes settle it, and
 * these are the ways those twelve bytes can be wrong.
 */
class GlbTest extends TestCase
{
    public function test_a_well_formed_avatar_is_read(): void
    {
        $glb = Glb::inspect($this->build());

        $this->assertTrue($glb->isVrm());
        $this->assertSame([Glb::VRM_EXTENSION], $glb->extensionsUsed());
    }

    public function test_a_model_with_no_humanoid_rig_is_read_but_is_not_an_avatar(): void
    {
        $glb = Glb::inspect($this->build(extensions: ['KHR_materials_unlit']));

        $this->assertFalse($glb->isVrm());
    }

    /**
     * Shallow on purpose, and separate from reading one.
     *
     * This is the question a sniffer asks — what kind of thing is this — and it
     * must not depend on the file being any good, or bytes that fail a later
     * check would be reported as an unknown type rather than as a broken model,
     * and the refusal would name the wrong problem.
     */
    public function test_recognising_one_does_not_depend_on_it_being_good(): void
    {
        $this->assertTrue(Glb::looksLikeOne($this->build(truthful: false)));
        $this->assertTrue(Glb::looksLikeOne('glTF'.str_repeat("\0", 8)));

        $this->assertFalse(Glb::looksLikeOne('glTF'));
        $this->assertFalse(Glb::looksLikeOne(''));
        $this->assertFalse(Glb::looksLikeOne('%PNG'.str_repeat("\0", 32)));
    }

    public function test_something_that_is_not_a_model_is_refused(): void
    {
        $this->expectExceptionMessage('not a glTF binary');

        Glb::inspect('%PNG'.str_repeat("\0", 64));
    }

    public function test_a_version_this_does_not_read_is_refused_by_number(): void
    {
        $this->expectExceptionMessage('version 3 glTF binary');

        Glb::inspect($this->build(version: 3));
    }

    /**
     * A file that disagrees with itself.
     *
     * The shape both truncation and padding take. Bytes after the declared end
     * are not part of the model, and a reader that ignored them is one that can
     * be handed a model with something else stapled to it.
     */
    public function test_a_file_that_lies_about_its_length_is_refused(): void
    {
        $this->expectExceptionMessage('bytes and it is');

        Glb::inspect($this->build(truthful: false));
    }

    public function test_a_file_with_no_chunks_is_refused(): void
    {
        $this->expectExceptionMessage('no chunks in it');

        Glb::inspect('glTF'.pack('VV', 2, 12));
    }

    /**
     * The specification puts the JSON first, so a file describing itself
     * somewhere else is not one this has to accommodate.
     */
    public function test_a_file_that_does_not_begin_with_its_json_is_refused(): void
    {
        $binary = pack('VV', 4, 0x004E4942).'____';

        $this->expectExceptionMessage('does not begin with its JSON');

        Glb::inspect('glTF'.pack('VV', 2, 12 + strlen($binary)).$binary);
    }

    public function test_a_chunk_claiming_more_than_the_file_holds_is_refused(): void
    {
        $json = '{"asset":{"version":"2.0"}}';

        // The header agrees with the file; the chunk inside it does not.
        $chunk = pack('VV', strlen($json) + 4096, 0x4E4F534A).$json;

        $this->expectExceptionMessage('more JSON than it contains');

        Glb::inspect('glTF'.pack('VV', 2, 12 + strlen($chunk)).$chunk);
    }

    public function test_a_description_that_is_not_readable_is_refused(): void
    {
        $this->expectExceptionMessage('could not be read');

        Glb::inspect($this->raw('{"asset":'));
    }

    public function test_a_description_that_is_not_an_object_is_refused(): void
    {
        $this->expectExceptionMessage('is not an object');

        Glb::inspect($this->raw('"a string"'));
    }

    /**
     * A declaration this cannot use is absent rather than fatal.
     *
     * `extensionsUsed` is somebody else's field and may hold anything. Reading
     * a model must not depend on it being the shape we hoped for, because the
     * question being asked — is this an avatar — has a perfectly good answer
     * when it is not.
     */
    public function test_an_extension_list_of_the_wrong_shape_is_ignored(): void
    {
        $this->assertSame([], Glb::inspect($this->raw('{"extensionsUsed":"not a list"}'))->extensionsUsed());

        $mixed = Glb::inspect($this->raw('{"extensionsUsed":["VRMC_vrm",{"no":1},7]}'));

        $this->assertSame([Glb::VRM_EXTENSION], $mixed->extensionsUsed());
        $this->assertTrue($mixed->isVrm());
    }

    /**
     * Nesting deep enough to be somebody being clever rather than a scene.
     */
    public function test_a_description_nested_beyond_reading_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        Glb::inspect($this->raw(str_repeat('[', 200).str_repeat(']', 200)));
    }

    /**
     * @param  array<int, string>  $extensions
     */
    private function build(array $extensions = [Glb::VRM_EXTENSION], bool $truthful = true, int $version = 2): string
    {
        return $this->raw((string) json_encode([
            'asset' => ['version' => '2.0'],
            'extensionsUsed' => $extensions,
        ]), $truthful, $version);
    }

    /**
     * A glTF binary around whatever description it is given.
     */
    private function raw(string $json, bool $truthful = true, int $version = 2): string
    {
        // Chunks are four byte aligned, and the JSON one pads with spaces.
        $json = str_pad($json, (int) (ceil(strlen($json) / 4) * 4), ' ');

        $chunk = pack('VV', strlen($json), 0x4E4F534A).$json;
        $length = 12 + strlen($chunk);

        return 'glTF'.pack('VV', $version, $truthful ? $length : $length + 64).$chunk;
    }
}
