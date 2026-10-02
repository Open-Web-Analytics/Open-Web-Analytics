<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackerBundle;

/**
 * Profile tracking bundles (PLAN 2.24): composed from the build's own files,
 * written atomically, and current exactly when the first line matches what
 * the Profile's settings and the build would write now.
 *
 * Built against a fixture build in a temporary directory, so no webpack run is
 * needed: CI's PHP job has none.
 */
final class TrackerBundleTest extends TestCase
{
    private const SITE = 'zz-bundle-profile';

    private string $dist;
    private string $out;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/owa-bundle-' . bin2hex(random_bytes(4));
        $this->dist = $root . '/dist/';
        $this->out  = $root . '/tracker/';
        mkdir($this->dist, 0700, true);

        $this->build('/*core*/', '/*chunk*/');

        TrackerBundle::$distDir = $this->dist;
        TrackerBundle::$outDir  = $this->out;
    }

    protected function tearDown(): void
    {
        TrackerBundle::$distDir = null;
        TrackerBundle::$outDir  = null;

        if (owa_test_db_available()) {
            foreach (array('tracker_clicks', 'tracker_session_cookie_days', 'tracker_url_fragments') as $key) {
                \OWA\Core\CoreAPI::clearScopedSetting('profile', self::SITE, 'base', $key);
            }
        }

        foreach (array($this->out, $this->dist) as $dir) {
            foreach ((array) glob($dir . '*') as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        @rmdir(dirname($this->dist));
    }

    /** A fixture build: a core and a domstream chunk, with a manifest that describes them. */
    private function build(string $core, string $chunk, ?string $chunkSha = null): void
    {
        file_put_contents($this->dist . 'owa.tracker.js', $core);
        file_put_contents($this->dist . 'owa.domstream.js', $chunk);
        file_put_contents($this->dist . 'owa.tracker.manifest.json', json_encode(array(
            'core'    => array('file' => 'owa.tracker.js', 'sha256' => hash('sha256', $core)),
            'plugins' => array('domstream' => array('file' => 'owa.domstream.js', 'sha256' => $chunkSha ?? hash('sha256', $chunk))),
        )));
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('reads and writes scoped settings');
        }
    }

    /** @return string[] the command names in a list */
    private static function names(array $commands): array
    {
        return array_map(fn ($c) => $c[0], $commands);
    }

    public function testTheDefaultsAreTheSnippetsCommands(): void
    {
        $config = TrackerBundle::config(self::SITE);

        $this->assertSame(array('setSiteId', self::SITE), $config['options'][0]);
        $this->assertContains(array('setOption', 'stateStoreExpirations', array('v' => 364, 's' => 364)), $config['options']);
        $this->assertContains(array('setOption', 'scrollThresholds', array(25, 50, 75, 90)), $config['options']);
        $this->assertContains(array('setSearchQueryParams', array('q', 's', 'search', 'query', 'keyword')), $config['options']);

        $this->assertSame(
            array('trackPageView', 'trackClicks', 'trackForms', 'trackScroll', 'trackSiteSearch'),
            array_values(array_diff(self::names($config['features']), array('trackDomStream'))));
    }

    /** The session window is the install's, so the tracker and the cube agree. */
    public function testTheSessionLengthIsTheInstalls(): void
    {
        $this->assertContains(
            array('setOption', 'sessionLength', (int) \OWA\Core\CoreAPI::getSetting('base', 'session_length')),
            TrackerBundle::config(self::SITE)['options']);
    }

    public function testAProfilesOverridesChangeItsCommands(): void
    {
        $this->requireDb();

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'base', 'tracker_clicks', false);
        \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'base', 'tracker_session_cookie_days', 0);
        \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'base', 'tracker_url_fragments', true);

        $config = TrackerBundle::config(self::SITE);

        $this->assertNotContains('trackClicks', self::names($config['features']));
        $this->assertContains(array('setOption', 'stateStoreExpirations', array('v' => 364, 's' => 0)), $config['options']);
        $this->assertContains(array('setTrackUrlFragments', true), $config['options']);
    }

    /** Domstream adds its recorder when the Profile records, and nothing when it does not. */
    public function testDomstreamContributesItsRecorder(): void
    {
        $module = new \OWA\Module\Domstream\Module();
        $empty  = array('options' => array(), 'features' => array(), 'plugins' => array());

        $on = $module->addToBundle($empty, self::SITE);

        $this->assertSame(array('domstream'), $on['plugins']);
        $this->assertSame(array(array('trackDomStream')), $on['features']);
        $this->assertSame(array(array('setDomstreamSampleRate', 100)), $on['options']);

        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'domstream', 'record', false);

            try {
                $this->assertSame($empty, $module->addToBundle($empty, self::SITE));
            } finally {
                \OWA\Core\CoreAPI::clearScopedSetting('profile', self::SITE, 'domstream', 'record');
            }
        }
    }

    /** Set while a test wants the domstream plugin in the config, whatever is active. */
    private static bool $withPlugin = false;

    public static function addPluginForTest(array $config): array
    {
        if (self::$withPlugin && !in_array('domstream', $config['plugins'], true)) {
            $config['plugins'][] = 'domstream';
        }

        return $config;
    }

    /** The order the measurement requires: header, preamble, each chunk, then the core. */
    public function testABundleIsTheHeaderThePreambleTheChunksAndTheCore(): void
    {
        static $registered = false;
        if (!$registered) {
            \OWA\Core\CoreAPI::registerFilter('tracker_bundle_config', array(self::class, 'addPluginForTest'), 999);
            $registered = true;
        }

        self::$withPlugin = true;

        try {
            $source = TrackerBundle::compose(self::SITE);
        } finally {
            self::$withPlugin = false;
        }

        $this->assertNotNull($source);

        $lines = explode("\n", $source);

        $this->assertMatchesRegularExpression('#^/\* owa-bundle 1 config=[0-9a-f]{64} build=[0-9a-f]{64} \*/$#', $lines[0]);
        $this->assertStringStartsWith('(function(w){var o=[["setSiteId","' . self::SITE . '"]', $lines[1]);

        $core  = strpos($source, '/*core*/');
        $chunk = strpos($source, '/*chunk*/');

        $this->assertNotFalse($core);
        $this->assertNotFalse($chunk, 'a Profile using a plugin gets its chunk');
        $this->assertLessThan($core, $chunk, 'a chunk goes before the core, or the core fetches it anyway');
    }

    /** A Profile that uses no plugin gets no chunk. */
    public function testABundleWithoutAPluginHasNoChunk(): void
    {
        $config = TrackerBundle::config(self::SITE);

        if (in_array('domstream', $config['plugins'], true)) {
            // Active here: turn recording off for this Profile.
            if (!owa_test_db_available()) {
                $this->markTestSkipped('needs a scoped setting to turn recording off');
            }
            \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'domstream', 'record', false);
        }

        try {
            $source = TrackerBundle::compose(self::SITE);
            $this->assertNotNull($source);
            $this->assertStringNotContainsString('/*chunk*/', $source);
        } finally {
            if (owa_test_db_available()) {
                \OWA\Core\CoreAPI::clearScopedSetting('profile', self::SITE, 'domstream', 'record');
            }
        }
    }

    public function testAFileTheManifestDoesNotDescribeIsNotComposed(): void
    {
        file_put_contents($this->dist . 'owa.tracker.js', '/*a different core*/');

        $this->assertNull(TrackerBundle::compose(self::SITE));
    }

    public function testWithoutABuildNothingIsComposedAndTheStatusSaysSo(): void
    {
        unlink($this->dist . 'owa.tracker.manifest.json');

        $this->assertNull(TrackerBundle::compose(self::SITE));
        $this->assertSame('unbuilt', TrackerBundle::status(self::SITE)['state']);
    }

    public function testPublishingWritesTheBundleAndItIsThenCurrent(): void
    {
        $this->assertSame('waiting', TrackerBundle::status(self::SITE)['state']);
        $this->assertFalse(TrackerBundle::isCurrent(self::SITE));

        $this->assertTrue(TrackerBundle::publish(self::SITE));

        $this->assertSame(TrackerBundle::compose(self::SITE), file_get_contents($this->out . self::SITE . '.js'));
        $this->assertTrue(TrackerBundle::isCurrent(self::SITE));
        $this->assertSame('published', TrackerBundle::status(self::SITE)['state']);
        $this->assertSame(array(), glob($this->out . '*.tmp'), 'no temporary file is left behind');
    }

    /** A new build is a new header, so every bundle is republished. */
    public function testANewBuildMakesABundleStale(): void
    {
        TrackerBundle::publish(self::SITE);

        $this->build('/*core v2*/', '/*chunk*/');

        $this->assertFalse(TrackerBundle::isCurrent(self::SITE));
        $this->assertSame(array(self::SITE => 'published'), TrackerBundle::publishStale(false, array(self::SITE)));
        $this->assertSame(array(self::SITE => 'current'), TrackerBundle::publishStale(false, array(self::SITE)));
    }

    /** A saved setting is a new header too. */
    public function testASavedSettingMakesABundleStale(): void
    {
        $this->requireDb();

        TrackerBundle::publish(self::SITE);
        $this->assertTrue(TrackerBundle::isCurrent(self::SITE));

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'base', 'tracker_clicks', false);

        $this->assertFalse(TrackerBundle::isCurrent(self::SITE));
    }

    /** A full run takes down the bundle of a Profile that is no longer live, and nothing else. */
    public function testABundleWhoseProfileIsGoneIsRemoved(): void
    {
        mkdir($this->out, 0700, true);
        file_put_contents($this->out . 'gone-profile.js', '/* old */');
        file_put_contents($this->out . 'notes.txt', 'not ours');
        TrackerBundle::publish(self::SITE);

        $this->assertSame(array('gone-profile'), TrackerBundle::removeOrphans(array(self::SITE)));

        $this->assertFileDoesNotExist($this->out . 'gone-profile.js');
        $this->assertFileExists($this->out . self::SITE . '.js');
        $this->assertFileExists($this->out . 'notes.txt');
    }

    public function testASiteIdThatCannotBeAFileNameIsRefused(): void
    {
        foreach (array('../escape', 'a/b', '', str_repeat('x', 65)) as $id) {
            $this->assertNull(TrackerBundle::path($id), var_export($id, true));
            $this->assertFalse(TrackerBundle::publish($id));
        }
    }

    public function testTheUrlIsUnderThePublicUrl(): void
    {
        $this->assertSame(
            rtrim((string) \OWA\Core\CoreAPI::getSetting('base', 'public_url'), '/') . '/public/tracker/' . self::SITE . '.js',
            TrackerBundle::url(self::SITE));
    }

    public function testAListSettingIsSplitOnCommas(): void
    {
        $this->assertSame(array('q', 's', 'term'), TrackerBundle::listOf(' q, s,, term ,'));
        $this->assertSame(array(), TrackerBundle::listOf(''));
    }
}
