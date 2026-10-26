<?php

namespace Tests\Integration;

use App\Models\SettingsModel;
use Tests\Support\DatabaseTestCase;

/**
 * The footer reads the settings from a cached copy — and a save is seen at once.
 *
 * footer.php printed $fbUrl, $igUrl, $snapUrl and $footerText, which nothing ever set, so the
 * social links were `#` whatever an admin saved. Reading the settings on every page would
 * cost a database round trip per page, so SettingsModel::cached() keeps a copy in a file and
 * update() — the one way the settings change — throws it away. The guard is that a save is
 * never hidden behind a stale copy.
 */
final class SettingsCacheTest extends DatabaseTestCase
{
    private string $dir;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        // Never the development copy in storage/cache: this test's values come from the test
        // database, and the local site would go on showing them.
        $this->dir  = sys_get_temp_dir() . '/cairo-settings-' . uniqid();
        $this->file = $this->dir . '/settings.json';
        SettingsModel::useCacheDir($this->dir);

        $this->pdo->exec("DELETE FROM website_settings");
        $this->pdo->exec("INSERT INTO website_settings (id, facebook_url) VALUES (1, 'https://facebook.com/before')");
    }

    protected function tearDown(): void
    {
        SettingsModel::useCacheDir(null);
        if (is_file($this->file)) {
            unlink($this->file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testTheCopyIsServedWithoutTheDatabase(): void
    {
        $this->assertSame('https://facebook.com/before', SettingsModel::cached()['facebook_url']);
        $this->assertFileExists($this->file);

        // Changed behind the model's back: the cached copy is what is served
        $this->pdo->exec("UPDATE website_settings SET facebook_url = 'https://facebook.com/behind' WHERE id = 1");
        $this->assertSame('https://facebook.com/before', SettingsModel::cached()['facebook_url']);
    }

    public function testASaveIsSeenOnTheNextRead(): void
    {
        SettingsModel::cached();

        $this->assertTrue(SettingsModel::update(['facebook_url' => 'https://facebook.com/after']));

        $this->assertSame('https://facebook.com/after', SettingsModel::cached()['facebook_url']);
    }
}
