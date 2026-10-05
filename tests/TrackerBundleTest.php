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
    private ?string $suiteOut = null;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/owa-bundle-' . bin2hex(random_bytes(4));
        $this->dist = $root . '/dist/';
        $this->out  = $root . '/tracker/';
        mkdir($this->dist, 0700, true);

        $this->build('/*core*/', '/*chunk*/');

        $this->suiteOut = TrackerBundle::$outDir;

        TrackerBundle::$distDir  = $this->dist;
        TrackerBundle::$buildDir = $this->dist;   // the fixture writes its manifest beside the files
        TrackerBundle::$outDir   = $this->out;
    }

    protected function tearDown(): void
    {
        TrackerBundle::$distDir  = null;
        TrackerBundle::$buildDir = null;
        TrackerBundle::$outDir  = $this->suiteOut;

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

    /**
     * Domstream adds its recorder when the Profile records, and nothing when it
     * does not.
     *
     * Both set on the Profile rather than read from the default: run on its own
     * in a fresh install the module is not active, so its declared defaults have
     * not been loaded, and a test leaning on them passed only after another
     * test had loaded them.
     */
    public function testDomstreamContributesItsRecorder(): void
    {
        $this->requireDb();

        $module = new \OWA\Module\Domstream\Module();
        $empty  = array('options' => array(), 'features' => array(), 'plugins' => array());

        try {
            \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'domstream', 'record', true);
            \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'domstream', 'sample_rate', 40);

            $on = $module->addToBundle($empty, self::SITE);

            $this->assertSame(array('domstream'), $on['plugins']);
            $this->assertSame(array(array('trackDomStream')), $on['features']);
            $this->assertSame(array(array('setDomstreamSampleRate', 40)), $on['options']);

            \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'domstream', 'record', false);

            $this->assertSame($empty, $module->addToBundle($empty, self::SITE));

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting('profile', self::SITE, 'domstream', 'record');
            \OWA\Core\CoreAPI::clearScopedSetting('profile', self::SITE, 'domstream', 'sample_rate');
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

    /**
     * What the Tracking Tag screen's pills show: a label for each feature the
     * saved settings turn on, in the order config() turns them on, and a
     * feature's command where nothing labels it.
     */
    public function testTrackedEventsAreTheFeaturesTheSettingsTurnOn(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs the settings store');
        }

        $events = TrackerBundle::trackedEvents(self::SITE);
        $this->assertContains('Clicks', $events, 'clicks are on by default');

        \OWA\Core\CoreAPI::setScopedSetting('profile', self::SITE, 'base', 'tracker_clicks', false);
        $this->assertNotContains('Clicks', TrackerBundle::trackedEvents(self::SITE), 'switched off, it goes');
        $this->assertCount(count($events) - 1, TrackerBundle::trackedEvents(self::SITE), 'and nothing else changes');

        $this->assertSame('Page interaction recording',
            (new \OWA\Module\Domstream\Module())->labelFeatures(array())['trackDomStream'],
            'a module labels the feature it adds');

        static $registered = false;
        if (!$registered) {
            \OWA\Core\CoreAPI::registerFilter('tracker_bundle_config', array(self::class, 'addUnlabelledFeatureForTest'), 999);
            $registered = true;
        }

        self::$withUnlabelled = true;
        try {
            $this->assertContains('trackSomethingNew', TrackerBundle::trackedEvents(self::SITE),
                'a feature nothing labels is named by its command');
        } finally {
            self::$withUnlabelled = false;
        }
    }

    private static bool $withUnlabelled = false;

    public static function addUnlabelledFeatureForTest($config)
    {
        if (self::$withUnlabelled) {
            $config['features'][] = array('trackSomethingNew');
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

    /** On an https page the bundle's beacons go over https, as the classic loader's do. */
    public function testThePreambleFollowsAnHttpsPage(): void
    {
        $preamble = TrackerBundle::preamble(TrackerBundle::config(self::SITE));

        $this->assertStringContainsString('w.location.protocol==="https:"', $preamble);
        $this->assertStringContainsString('w.owa_baseSecUrl||w.owa_baseUrl.replace(/^http:/,"https:")', $preamble);
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

    // ---------------------------------------------------------------------
    // What starts a publish (PLAN 2.30.7)
    // ---------------------------------------------------------------------

    /**
     * The build is identified by what it built: the hash of the manifest the
     * build writes beside the tracker. Nothing committed; a new build is a new
     * hash, and no build is none.
     */
    public function testTheBuildIsIdentifiedByItsManifest(): void
    {
        $first = TrackerBundle::buildHash();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame($first, \OWA\Module\Base\Module::requiredTrackerBuild());
        $this->assertSame($first, TrackerBundle::buildHash(), 'the same build, the same hash');

        $this->build('/*core of the next release*/', '/*chunk*/');
        $this->assertNotSame($first, TrackerBundle::buildHash(), 'a new build is a new hash');

        unlink($this->dist . 'owa.tracker.manifest.json');
        $this->assertSame('', TrackerBundle::buildHash(), 'no build, no hash');
    }

    /**
     * A new tracker is an update (PLAN 2.30.7): a recorded tracker build
     * other than the one built here is an update pending, and the update publishes
     * every live Profile's bundle that is stale or missing -- which is how
     * Profiles from 1.x, which have none, get theirs on the upgrade.
     */
    public function testANewTrackerIsAnUpdateThatRepublishesStaleBundles(): void
    {
        $this->requireDb();

        $base = \OWA\Core\CoreAPI::serviceSingleton()->getModule('base');
        $c    = \OWA\Core\CoreAPI::configSingleton();
        $was  = $c->get('base', 'tracker_build');

        // The install's own live Profiles, published into this test's directory.
        $live = TrackerBundle::siteIds();

        if (!$live) {
            $this->markTestSkipped('needs a live web Profile');
        }

        try {
            TrackerBundle::publish($live[0]);
            // Every other live Profile has no bundle at all, as a Profile from 1.x has none.
            foreach (array_slice($live, 1) as $site_id) {
                $this->assertFileDoesNotExist($this->out . $site_id . '.js');
            }
            $this->build('/*core of the new release*/', '/*chunk*/');
            $this->assertFalse(TrackerBundle::isCurrent($live[0]), 'the new build makes it stale');

            $c->set('base', 'tracker_build', 'an-older-build');
            $this->assertFalse($base->isUpToDate(), 'an older tracker is an update pending');
            $this->assertTrue($base->isSchemaCurrent(), 'and only that: the schema is current');

            // cmd=update: published in the run.
            \OWA\Module\Base\Module::$publish_inline = true;
            $this->assertTrue($base->update());

            $this->assertSame(\OWA\Module\Base\Module::requiredTrackerBuild(), (string) $c->get('base', 'tracker_build'));
            $this->assertTrue($base->isUpToDate());

            foreach ($live as $site_id) {
                $this->assertTrue(TrackerBundle::isCurrent($site_id), "$site_id's bundle was republished");
            }
        } finally {
            \OWA\Module\Base\Module::$publish_inline = null;
            $this->restoreTrackerBuild($was);
        }
    }

    /**
     * Put the recorded build back as it was, in the DATABASE as well: update()
     * persists the fixture's hash, and leaving it would make every later test
     * on this install see an update pending.
     */
    private function restoreTrackerBuild($was): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();
        $c->set('base', 'tracker_build', $was);

        if ($was === null || $was === false || $was === '') {
            \OWA\Core\CoreAPI::clearScopedSetting('install', '1', 'base', 'tracker_build');
        } else {
            $c->persistSetting('base', 'tracker_build', $was);
            $c->save();
        }
    }

    /** The same update from the update screen queues the publish: a bundle per Profile is not work for a web request. */
    public function testAnUpdateFromTheScreenQueuesThePublish(): void
    {
        $table = $this->scratchJobQueue();
        $base  = \OWA\Core\CoreAPI::serviceSingleton()->getModule('base');
        $c     = \OWA\Core\CoreAPI::configSingleton();
        $was   = $c->get('base', 'tracker_build');
        $live  = TrackerBundle::siteIds();

        try {
            $c->set('base', 'tracker_build', 'an-older-build');
            \OWA\Module\Base\Module::$publish_inline = false;

            $this->assertTrue($base->update());

            $this->assertTrue(\OWA\Module\Base\Classes\JobQueue::isQueued('publish-trackers', 'publish-trackers:all'));
            foreach ($live as $site_id) {
                $this->assertFileDoesNotExist($this->out . $site_id . '.js', 'nothing written in the request');
            }
            $this->assertSame(\OWA\Module\Base\Module::requiredTrackerBuild(), (string) $c->get('base', 'tracker_build'), 'the update is still recorded');
        } finally {
            \OWA\Module\Base\Module::$publish_inline = null;
            $this->dropScratchJobQueue($table);
            $this->restoreTrackerBuild($was);
        }
    }

    public function testRemoveTakesDownOneBundle(): void
    {
        TrackerBundle::publish(self::SITE);

        $this->assertTrue(TrackerBundle::remove(self::SITE));
        $this->assertFileDoesNotExist($this->out . self::SITE . '.js');
        $this->assertFalse(TrackerBundle::remove(self::SITE), 'nothing left to remove');
    }

    /** @return string the scratch job table, set for this test */
    private function scratchJobQueue(): string
    {
        $this->requireDb();

        $table = 'owa_job_queue_phpunit_bundles';
        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' LIKE owa_job_queue');
        \OWA\Module\Base\Classes\JobQueue::$table = $table;

        return $table;
    }

    private function dropScratchJobQueue(string $table): void
    {
        \OWA\Module\Base\Classes\JobQueue::$table = null;
        \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . $table);
    }

    /** A Profile's save publishes it now; one that cannot be written now is queued, not left. */
    public function testPublishNowQueuesWhatItCannotWrite(): void
    {
        $table = $this->scratchJobQueue();

        try {
            $this->assertTrue(TrackerBundle::publishNow(self::SITE));
            $this->assertFalse(\OWA\Module\Base\Classes\JobQueue::isQueued('publish-trackers'));

            unlink($this->dist . 'owa.tracker.manifest.json');

            $this->assertFalse(TrackerBundle::publishNow(self::SITE));
            $this->assertTrue(\OWA\Module\Base\Classes\JobQueue::isQueued('publish-trackers', 'publish-trackers:' . self::SITE));

            $job = \OWA\Module\Base\Classes\JobQueue::listJobs('pending')[0];
            $this->assertSame(array('site' => self::SITE), json_decode($job['params'], true));
        } finally {
            $this->dropScratchJobQueue($table);
        }
    }

    /** A change above one Profile is one queued run, however many saves. */
    public function testWiderChangesQueueOneFullPublish(): void
    {
        $table = $this->scratchJobQueue();

        try {
            $first = TrackerBundle::scheduleFullPublish();
            $this->assertNotFalse($first);
            $this->assertSame($first, TrackerBundle::scheduleFullPublish());
            $this->assertCount(1, \OWA\Module\Base\Classes\JobQueue::listJobs('pending'));
        } finally {
            $this->dropScratchJobQueue($table);
        }
    }

    /** An install-level save queues a publish when it touched a tag setting, and not otherwise. */
    public function testAnInstallLevelTagSettingSaveQueuesAFullPublish(): void
    {
        $table = $this->scratchJobQueue();
        $base  = \OWA\Core\CoreAPI::serviceSingleton()->getModule('base');
        $d     = \OWA\Core\CoreAPI::getEventDispatch();
        $saved = function (string $module, array $keys) use ($d) {
            $e = $d->makeEvent('base.install_settings_saved');
            $e->set('module', $module);
            $e->set('keys', $keys);
            return $e;
        };

        try {
            $base->tagSettingsSavedHandler($saved('base', array('announce_visitors')));
            $base->tagSettingsSavedHandler($saved('domstream', array('tracker_clicks')));
            $this->assertFalse(\OWA\Module\Base\Classes\JobQueue::isQueued('publish-trackers'),
                'not a tag setting, or not that module\'s');

            $base->tagSettingsSavedHandler($saved('base', array('announce_visitors', 'tracker_clicks')));
            $this->assertTrue(\OWA\Module\Base\Classes\JobQueue::isQueued('publish-trackers', 'publish-trackers:all'));
        } finally {
            $this->dropScratchJobQueue($table);
        }
    }
}
