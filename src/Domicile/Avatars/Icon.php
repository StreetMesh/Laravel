<?php

namespace StreetMesh\Server\Domicile\Avatars;

use GdImage;
use RuntimeException;

/**
 * A picture of somebody, in the one shape this server will serve.
 *
 * Every icon is re-encoded rather than kept as it arrived, and that is the
 * whole purpose of this class. These bytes come back from a resident's own
 * hostname — the same origin that answers for their identity — so serving a
 * stranger's file back unaltered from that address is not something to do
 * carefully. It is something not to do.
 *
 * Re-encoding settles four things at once. Whatever was uploaded is now
 * demonstrably an image, because it survived being decoded. Its EXIF is gone,
 * including the location the camera recorded. Its dimensions are ours. And an
 * SVG full of script, or a PNG with a payload after the image data, does not
 * survive the round trip at all.
 *
 * Square, because every place that draws one draws it in a circle, and a
 * picture cropped by CSS is cropped differently in each of them.
 */
final class Icon
{
    /**
     * Big enough for a retina circle at the sizes anything here draws, small
     * enough that a party of four costs less than one photograph.
     */
    public const SIZE = 256;

    /**
     * The ceiling on what will be decoded at all.
     *
     * Checked from the header before a single pixel is allocated, because the
     * attack is a small file that claims to be enormous: 30,000 by 30,000
     * pixels is a hundred kilobytes on the wire and about three gigabytes once
     * a decoder believes it.
     */
    private const LARGEST = 8192;

    private function __construct(public readonly string $bytes) {}

    /**
     * Take what somebody uploaded and give back what will be stored.
     *
     * Throws with something a person can act on, because this is reached from
     * a form somebody is looking at.
     */
    public static function from(string $uploaded): self
    {
        [$width, $height] = self::measure($uploaded);

        $source = @imagecreatefromstring($uploaded);

        if (! $source instanceof GdImage) {
            throw new RuntimeException('That file could not be read as an image.');
        }

        try {
            return new self(self::encode(self::square($source, $width, $height)));
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * How big it says it is, refused before it is believed.
     *
     * @return array{int, int}
     */
    private static function measure(string $uploaded): array
    {
        $size = @getimagesizefromstring($uploaded);

        if ($size === false) {
            throw new RuntimeException('That file is not an image this server can read.');
        }

        [$width, $height] = $size;

        if ($width < 1 || $height < 1) {
            throw new RuntimeException('That image has no size.');
        }

        if ($width > self::LARGEST || $height > self::LARGEST) {
            throw new RuntimeException(
                'That image is larger than '.self::LARGEST.' pixels on a side, which is more than an icon needs.'
            );
        }

        return [$width, $height];
    }

    /**
     * The middle of it, scaled, on transparency.
     *
     * Cropped from the centre rather than squashed, because a face squashed to
     * fit is worse than a face with its edges trimmed — and because whoever
     * uploaded it aimed at the middle.
     */
    private static function square(GdImage $source, int $width, int $height): GdImage
    {
        $side = min($width, $height);

        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);

        if (! $canvas instanceof GdImage) {
            throw new RuntimeException('This server could not make room for that image.');
        }

        /*
         * Kept rather than flattened onto a colour, because a circle drawn over
         * an unknown background is exactly where transparency matters and there
         * is no colour here that would be right in both light and dark.
         */
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        imagecopyresampled(
            $canvas,
            $source,
            0, 0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            self::SIZE, self::SIZE,
            $side, $side,
        );

        return $canvas;
    }

    private static function encode(GdImage $canvas): string
    {
        try {
            ob_start();

            imagepng($canvas, null, 9);

            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($canvas);
        }

        if ($bytes === '') {
            throw new RuntimeException('That image could not be re-encoded.');
        }

        return $bytes;
    }
}
