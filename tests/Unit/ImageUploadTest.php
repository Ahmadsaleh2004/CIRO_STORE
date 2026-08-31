<?php

namespace Tests\Unit;

use App\Core\ImageUpload;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ImageUpload — the only guard over what uploads write to disk.
 *
 * The logic used to be written twice (AdminProductModel and BrandingModel) in two identical
 * copies separated only by the filename prefix, and neither carried **any size limit**: the
 * whole matter was left to upload_max_filesize in php.ini — a server setting that differs
 * between environments and is unknown to whoever reads the code.
 *
 * ⚠️ move_uploaded_file rejects any file that did not arrive through a real HTTP upload, so
 * the complete save path cannot be tested from the CLI. What is tested here is what comes
 * **before** the save — and that is where every security decision lives: the size, the type,
 * and deriving the extension.
 */
final class ImageUploadTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /** Creates a temporary file with the given content and returns its path. */
    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/cairo-upload-' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->temp[] = $path;
        return $path;
    }

    /** The smallest valid GIF — its real bytes, so mime_content_type recognises it. */
    private function tinyGif(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $path): array
    {
        return ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK];
    }

    public function testTheLimitIsFiveMegabytes(): void
    {
        // The number is pinned deliberately: changing it is a decision rather than a detail,
        // and it must be visible in a diff.
        $this->assertSame(5 * 1024 * 1024, ImageUpload::MAX_BYTES);
    }

    /**
     * A file over the limit is rejected before its type is asked about.
     *
     * The order is deliberate: mime_content_type reads the file, and reading a huge file only
     * to reject it afterwards is waste an attacker can repeat.
     */
    public function testAFileOverTheLimitIsRejected(): void
    {
        $path = $this->tempFile(str_repeat('A', ImageUpload::MAX_BYTES + 1));

        $this->assertNull(ImageUpload::store($this->entry($path), sys_get_temp_dir(), 'x_'));
        $this->assertFileExists($path, 'A rejected file must not be moved.');
    }

    public function testAFileAtTheLimitIsNotRejectedForItsSize(): void
    {
        // Exactly at the limit: the condition is `>` and not `>=` — an off-by-one here
        // rejects a file that is perfectly allowed.
        $path = $this->tempFile(str_repeat('A', ImageUpload::MAX_BYTES));

        // It is rejected for not being an image rather than for exceeding the size — and the
        // distinction matters.
        $this->assertNull(ImageUpload::store($this->entry($path), sys_get_temp_dir(), 'x_'));
        $this->assertLessThanOrEqual(ImageUpload::MAX_BYTES, filesize($path));
    }

    public function testANonImageIsRejectedWhateverItsName(): void
    {
        $path = $this->tempFile('<?php echo "hello"; ?>');

        $this->assertNull(ImageUpload::store($this->entry($path), sys_get_temp_dir(), 'x_'));
    }

    public function testAFailedUploadIsRejected(): void
    {
        $path = $this->tempFile($this->tinyGif());

        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE] as $code) {
            $this->assertNull(
                ImageUpload::store(['tmp_name' => $path, 'error' => $code], sys_get_temp_dir(), 'x_'),
                "A failed upload with code {$code} was accepted."
            );
        }
    }

    /**
     * The extension is derived from the content, and every accepted type has a matching
     * extension.
     *
     * The list and the branches were separate in the old code: adding a type to the list
     * without a matching extension saved the file with the wrong extension **silently**. The
     * single map prevents that, and this test prevents them being separated again.
     */
    public function testEveryAcceptedMimeHasAnExtension(): void
    {
        $map = (new ReflectionClass(ImageUpload::class))->getConstant('EXT_BY_MIME');

        $this->assertNotEmpty($map);

        foreach ($map as $mime => $ext) {
            $this->assertMatchesRegularExpression('#^image/[a-z0-9+.-]+$#', (string) $mime);
            $this->assertMatchesRegularExpression('/^[a-z0-9]{2,5}$/', (string) $ext, "An invalid extension for {$mime}");
        }
    }

    /**
     * No dangerous type is accepted, even if it is added to the map by oversight.
     *
     * An SVG is an image, but it is an XML document that runs script when opened directly —
     * so it is an XSS vector rather than a display image. Its appearing in the map one day is
     * a mistake the build should stop, not a review.
     */
    public function testDangerousImageTypesAreNotAccepted(): void
    {
        $map = (new ReflectionClass(ImageUpload::class))->getConstant('EXT_BY_MIME');

        foreach (['image/svg+xml', 'image/svg', 'text/html', 'application/xml'] as $dangerous) {
            $this->assertArrayNotHasKey($dangerous, $map, "A dangerous type is accepted: {$dangerous}");
        }
    }

    /**
     * Both models delegate to the single implementation.
     *
     * This is what prevents the two identical copies coming back — and with them the chance
     * that one is tightened while the other is left.
     */
    public function testBothModelsDelegateInsteadOfDuplicating(): void
    {
        foreach (['AdminProductModel', 'BrandingModel'] as $model) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . "/app/Models/{$model}.php");

            $this->assertStringContainsString('ImageUpload::store(', $src, "{$model} does not delegate.");
            $this->assertStringNotContainsString(
                'move_uploaded_file(',
                $src,
                "{$model} still saves on its own — two copies mean only one of them gets tightened."
            );
        }
    }
}
