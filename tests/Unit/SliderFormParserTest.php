<?php

namespace Tests\Unit;

use App\Services\SliderFormParser;
use PHPUnit\Framework\TestCase;

/**
 * SliderFormParser — the validation that could not be tested.
 *
 * These checks are the first justification for extracting the service. The same logic used
 * to live inside AdminBrandingController::save, and every error path in it ended with a
 * header() and an exit — which is to say testing it from PHPUnit meant ending the process.
 * The result was eight rejection paths, none of them covered.
 *
 * ⚠️ Uploading is not tested here: move_uploaded_file rejects any file that did not arrive
 * through a real HTTP upload, so the upload path is covered in ImageUploadTest. What is
 * tested here is the decision: what is accepted, what is rejected, and with which message.
 */
final class SliderFormParserTest extends TestCase
{
    private const DIR = '/tmp/does-not-matter';

    /** One valid slide with an already-existing image. */
    /**
     * @return list<array<string, mixed>>
     */
    private function validSlides(): array
    {
        return [
            [
                'items' => [
                    [
                        'active_mode'           => 'manual',
                        'existing_manual_image' => 'images/old.jpg',
                    ],
                ],
            ],
        ];
    }

    public function testAValidSlideIsPrepared(): void
    {
        $r = SliderFormParser::parse($this->validSlides(), [], self::DIR);

        $this->assertNull($r['error']);
        $this->assertCount(1, $r['slides']);
        $this->assertSame('images/old.jpg', $r['slides'][0]['items'][0]['manual_image_path']);
        $this->assertSame(['images/old.jpg'], $r['images']);
        $this->assertSame([], $r['uploaded']);
    }

    // ════════════════════════════════════════════════════════
    // The title line
    // ════════════════════════════════════════════════════════

    public function testTitlesArePassedThroughTrimmed(): void
    {
        $r = SliderFormParser::parse([
            [
                'items' => [
                    [
                        'active_mode'           => 'manual',
                        'existing_manual_image' => 'images/old.jpg',
                        'manual_title'          => '  Galaxy S24 Ultra  ',
                        'manual_description'    => '  Flagship phone.  ',
                    ],
                ],
            ],
        ], [], self::DIR);

        $this->assertNull($r['error']);
        $this->assertSame('Galaxy S24 Ultra', $r['slides'][0]['items'][0]['manual_title']);
        $this->assertSame('Flagship phone.', $r['slides'][0]['items'][0]['manual_description']);
    }

    public function testAnEmptyTitleBecomesNullNotAnEmptyString(): void
    {
        $r = SliderFormParser::parse([
            [
                'items' => [
                    [
                        'active_mode'           => 'manual',
                        'existing_manual_image' => 'images/old.jpg',
                        'manual_title'          => '   ',
                    ],
                ],
            ],
        ], [], self::DIR);

        // The difference is not cosmetic: the read in BrandingModel uses
        // COALESCE(NULLIF(product_title,''), p.name) — and `''` passes COALESCE but NULLIF
        // converts it, while null passes straight through. Normalising empty to null keeps
        // the database column meaning exactly one thing: "the admin wrote no title".
        $this->assertNull($r['slides'][0]['items'][0]['manual_title']);
    }

    /**
     * The title is not required — an item without one passes and is drawn with its
     * description alone.
     */
    public function testAnItemWithoutATitleIsStillValid(): void
    {
        $r = SliderFormParser::parse($this->validSlides(), [], self::DIR);

        $this->assertNull($r['error']);
        $this->assertNull($r['slides'][0]['items'][0]['manual_title']);
        $this->assertNull($r['slides'][0]['items'][0]['product_title']);
    }

    public function testAnEmptyFormIsRejected(): void
    {
        $r = SliderFormParser::parse([], [], self::DIR);

        $this->assertSame('Please add at least one slide before saving.', $r['error']);
        $this->assertSame([], $r['slides']);
    }

    public function testTooManySlidesAreRejected(): void
    {
        $slides = array_fill(0, SliderFormParser::MAX_SLIDES + 1, $this->validSlides()[0]);

        $r = SliderFormParser::parse($slides, [], self::DIR);

        $this->assertStringContainsString('Too many slides', (string) $r['error']);
    }

    public function testTooManyImagesInOneSlideAreRejected(): void
    {
        $item   = $this->validSlides()[0]['items'][0];
        $slides = [['items' => array_fill(0, SliderFormParser::MAX_ITEMS_PER_SLIDE + 1, $item)]];

        $r = SliderFormParser::parse($slides, [], self::DIR);

        $this->assertStringContainsString('too many images', (string) $r['error']);
    }

    /**
     * A slide with no images is ignored silently — not rejected.
     *
     * The interface adds an empty slide when "add" is pressed, so rejecting it would block
     * saving for anyone who added one and then changed their mind.
     */
    public function testAnEmptySlideIsSkippedNotRejected(): void
    {
        $r = SliderFormParser::parse(
            [['items' => []], $this->validSlides()[0]],
            [],
            self::DIR
        );

        $this->assertNull($r['error']);
        $this->assertCount(1, $r['slides']);
    }

    public function testAFormOfOnlyEmptySlidesIsRejected(): void
    {
        $r = SliderFormParser::parse([['items' => []], ['items' => []]], [], self::DIR);

        $this->assertStringContainsString('at least one valid slide', (string) $r['error']);
    }

    public function testProductModeWithoutAProductIsRejected(): void
    {
        $r = SliderFormParser::parse(
            [['items' => [['active_mode' => 'product', 'product_id' => 0]]]],
            [],
            self::DIR
        );

        $this->assertStringContainsString('must have a product selected', (string) $r['error']);
    }

    public function testManualModeWithoutAnImageIsRejected(): void
    {
        $r = SliderFormParser::parse(
            [['items' => [['active_mode' => 'manual']]]],
            [],
            self::DIR
        );

        $this->assertStringContainsString('must have a product selected', (string) $r['error']);
    }

    /**
     * Any mode other than 'product' is treated as 'manual'.
     *
     * The value comes from the form so it can be anything. The safe default matters: an
     * unknown mode must not bypass both required-field conditions.
     */
    public function testAnUnknownModeFallsBackToManualAndIsStillValidated(): void
    {
        $r = SliderFormParser::parse(
            [['items' => [['active_mode' => 'nonsense', 'product_id' => 5]]]],
            [],
            self::DIR
        );

        $this->assertStringContainsString('must have a product selected', (string) $r['error']);
    }

    // ── The dangerous links ──────────────────────────────────

    /**
     * @return list<array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JaVaScRiPt:alert(1)'],
            ['  javascript:alert(1)'],
            ["java\tscript:alert(1)"],
            ["java\nscript:alert(1)"],
            ['data:text/html,<script>alert(1)</script>'],
            ['vbscript:msgbox(1)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeUrls')]
    public function testAnUnsafeLinkIsRejected(string $url): void
    {
        $r = SliderFormParser::parse(
            [['items' => [[
                'active_mode'           => 'manual',
                'existing_manual_image' => 'images/x.jpg',
                'manual_link_url'       => $url,
            ]]]],
            [],
            self::DIR
        );

        $this->assertStringContainsString('Unsafe link URL', (string) $r['error'], "A dangerous link was accepted: {$url}");
    }

    public function testOrdinaryLinksPass(): void
    {
        foreach (['https://example.test/x', '/products?id=3', 'mailto:a@b.test', ''] as $url) {
            $this->assertFalse(SliderFormParser::isUnsafeUrl($url), "A sound link was rejected: {$url}");
        }

        $this->assertFalse(SliderFormParser::isUnsafeUrl(null));
    }

    /**
     * The $_FILES reader understands the inverted structure PHP produces.
     *
     * The five properties are read from five parallel paths rather than from one array —
     * and that is what whoever touches this code gets wrong most often.
     */
    public function testTheFileReaderUnderstandsPhpsInvertedStructure(): void
    {
        $files = [
            'name'     => [0 => ['items' => [1 => ['manual_image' => 'a.jpg']]]],
            'type'     => [0 => ['items' => [1 => ['manual_image' => 'image/jpeg']]]],
            'tmp_name' => [0 => ['items' => [1 => ['manual_image' => '/tmp/phpABC']]]],
            'error'    => [0 => ['items' => [1 => ['manual_image' => UPLOAD_ERR_OK]]]],
            'size'     => [0 => ['items' => [1 => ['manual_image' => 1234]]]],
        ];

        $entry = SliderFormParser::extractFileEntry($files, 0, 1);

        $this->assertSame('/tmp/phpABC', $entry['tmp_name']);
        $this->assertSame('a.jpg', $entry['name']);
        $this->assertSame(UPLOAD_ERR_OK, $entry['error']);
        $this->assertSame(1234, $entry['size']);
    }

    public function testTheFileReaderReturnsNullWhenNoFileWasSent(): void
    {
        $this->assertNull(SliderFormParser::extractFileEntry([], 0, 0));
        $this->assertNull(SliderFormParser::extractFileEntry(
            ['tmp_name' => [0 => ['items' => [0 => ['manual_image' => '']]]]],
            0,
            0
        ));
    }
}
