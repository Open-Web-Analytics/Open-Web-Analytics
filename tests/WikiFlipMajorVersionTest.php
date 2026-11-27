<?php

require_once __DIR__ . '/tools/wiki/flip-major-version.php';

use PHPUnit\Framework\TestCase;

/**
 * tests/tools/wiki/flip-major-version.php on a small wiki built in a temp
 * folder: 1.x at the root, 2.0 staged in v2/.
 */
final class WikiFlipMajorVersionTest extends TestCase
{
    private string $wiki;

    protected function setUp(): void
    {

        $this->wiki = sys_get_temp_dir() . '/owa-wiki-flip-' . getmypid() . '-' . bin2hex(random_bytes(4));

        self::write($this->wiki, [
            'Home.md' => "Welcome.\n\n| Page | What |\n|---|---|\n| [[Javascript Tracker]] | the tag |\n"
                . "| [[Configuration\\|Configuration]] | settings |\n\n"
                . "See https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Configuration#top.\n",
            'Configuration.md' => "> **This page documents OWA 1.x. It has been superseded by the 2.0 documentation: see [[Old|Old]].**\n\n"
                . "Set [the keys](Configuration#keys) and [[the tracker|Javascript-Tracker#options]].\n"
                . "<!-- BEGIN GENERATED: settings -->\n| [[Home]] | first |\n[back](Home)\n<!-- END GENERATED: settings -->\n"
                . "`[[Home]]` is how to link it.\n```\n[x](Home)\n```\n![shot](images/a.png)\n"
                . "[GitHub](https://github.com/Open-Web-Analytics/Open-Web-Analytics)\n",
            'Javascript-Tracker.md' => "[CLI](Command-Line-Interface-(CLI)#run) and [[v2 settings|v2-Settings]].\n",
            'Command-Line-Interface-(CLI).md' => "Run it.\n",
            'Event-Queueing.md' => "Queues.\n",
            'Old-Only.md' => "Gone in 2.0.\n",
            '_Sidebar.md' => "- [Home](Home)\n- [Configuration](Configuration)\n",
            'images/a.png' => 'png',
            'v2/v2-Home.md' => "> **This is the documentation for OWA 2.0, which has not been released yet.** For the current release, see [[Home]].\n"
                . ">\n> 2.0 pages are named with a `v2-` prefix.\n\nStart with [[Configuration|v2-Configuration]].\n",
            'v2/v2-Configuration.md' => self::banner('[[Configuration]]') . "# Configuration\n\n"
                . "| Key | See |\n|---|---|\n| a | [[Settings\\|v2-Settings#keys]] |\n\n"
                . "The [tracker](v2-Javascript-Tracker#options), the [home](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/v2-Home) and [[Upgrading-from-1.x]].\n"
                . "<!-- BEGIN GENERATED: settings -->\n- [[v2-Settings]]\n<!-- END GENERATED: settings -->\n"
                . "`[[v2-Home]]` and\n```\n[[v2-Home]]\n```\n",
            'v2/v2-Settings.md' => self::banner('[[Configuration]]') . "Settings.\n",
            'v2/v2-Javascript-Tracker.md' => self::banner('[[Javascript Tracker|Javascript-Tracker]]') . "Tracker.\n",
            'v2/v2-Command-Line-Interface.md' => self::banner('[[Command Line Interface (CLI)|Command-Line-Interface-(CLI)]]') . "CLI.\n",
            'v2/v2-Server-Roles.md' => self::banner('[[Home]]') . "Roles.\n",
            'v2/Upgrading-from-1.x.md' => "> **This is the documentation for OWA 2.0, which has not been released yet.** 2.0 pages start at [[v2 Home|v2-Home]]. For updating within 1.x, see [[Home]].\n\n"
                . "See [[Server Roles|v2-Server-Roles]].\n",
            'v2/_Sidebar.md' => "- [Home](v2-Home)\n- [Configuration](v2-Configuration)\n- [Command Line](v2-Command-Line-Interface)\n"
                . "### Older releases\n- [1.x documentation](Home)\n",
        ]);
    }

    protected function tearDown(): void
    {

        self::remove($this->wiki);
    }

    private static function banner(string $twin): string
    {

        return "> **This is the documentation for OWA 2.0, which has not been released yet.** 2.0 pages start at [[v2 Home|v2-Home]]. For the current release, see $twin.\n\n";
    }

    /** @param array<string, string> $files */
    private static function write(string $root, array $files): void
    {

        foreach ($files as $rel => $text) {
            if (!is_dir(dirname("$root/$rel"))) {
                mkdir(dirname("$root/$rel"), 0755, true);
            }
            file_put_contents("$root/$rel", $text);
        }
    }

    private static function remove(string $path): void
    {

        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $f) {
                if ($f !== '.' && $f !== '..') {
                    self::remove("$path/$f");
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    /** @return array<string, string> relative path => contents, every file */
    private function tree(?string $under = null): array
    {

        $root = $under === null ? $this->wiki : "$this->wiki/$under";
        $out  = [];

        foreach (owa_wiki_flip_files($root) as $rel) {
            $out[$rel] = (string) file_get_contents("$root/$rel");
        }

        return $out;
    }

    private function page(string $rel): string
    {

        $this->assertFileExists("$this->wiki/$rel");

        return (string) file_get_contents("$this->wiki/$rel");
    }

    /** @return array{0: int, 1: string} exit code, output */
    private function cli(string ...$args): array
    {

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tests/tools/wiki/flip-major-version.php')
             . ' --wiki ' . escapeshellarg($this->wiki) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        exec($cmd, $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    private function flip(): void
    {

        [$rc, $out] = $this->cli('--from', 'v2', '--to', 'v1', '--apply');

        $this->assertSame(0, $rc, $out);
    }

    public function testADryRunListsEachChangeAndWritesNothing(): void
    {

        $before = $this->tree();

        [$rc, $out] = $this->cli('--from', 'v2', '--to', 'v1');

        $this->assertSame(0, $rc, $out);
        $this->assertSame($before, $this->tree());
        $this->assertStringContainsString("Renames (15)\n", $out);
        $this->assertStringContainsString('  v2/v2-Home.md -> Home.md', $out);
        $this->assertStringContainsString("Banners removed (7)\n", $out);
        $this->assertStringContainsString("Banners added (6)\n", $out);
        $this->assertStringContainsString('  v1/v1-Old-Only.md: [[Home|Home]] (no counterpart)', $out);
        $this->assertStringContainsString('  Configuration.md: [tracker](v2-Javascript-Tracker#options) -> [tracker](Javascript-Tracker#options)', $out);
        $this->assertStringContainsString("Problems (0)\n", $out);
    }

    public function testPagesAndSidebarsMove(): void
    {

        $this->flip();

        $this->assertSame([
            'Command-Line-Interface.md', 'Configuration.md', 'Home.md', 'Javascript-Tracker.md',
            'Server-Roles.md', 'Settings.md', 'Upgrading-from-1.x.md', '_Sidebar.md', 'images/a.png',
            'v1/_Sidebar.md', 'v1/v1-Command-Line-Interface-(CLI).md', 'v1/v1-Configuration.md',
            'v1/v1-Event-Queueing.md', 'v1/v1-Home.md', 'v1/v1-Javascript-Tracker.md', 'v1/v1-Old-Only.md',
        ], array_keys($this->tree()));
        $this->assertDirectoryDoesNotExist("$this->wiki/v2");
        $this->assertSame('png', $this->page('images/a.png'));
    }

    public function testLinksInEveryFormAreRewritten(): void
    {

        $this->flip();

        // 1.x: links to 1.x pages gain the prefix, in a table with \| and in a generated block.
        $home = $this->page('v1/v1-Home.md');
        $this->assertStringContainsString('| [[Javascript Tracker\\|v1-Javascript-Tracker]] | the tag |', $home);
        $this->assertStringContainsString('| [[Configuration\\|v1-Configuration]] | settings |', $home);
        $this->assertStringContainsString('https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/v1-Configuration#top.', $home);

        $conf = $this->page('v1/v1-Configuration.md');
        $this->assertStringContainsString('[the keys](v1-Configuration#keys)', $conf);
        $this->assertStringContainsString('[[the tracker|v1-Javascript-Tracker#options]]', $conf);
        $this->assertStringContainsString("<!-- BEGIN GENERATED: settings -->\n| [[Home\\|v1-Home]] | first |\n[back](v1-Home)\n<!-- END GENERATED: settings -->", $conf);
        $this->assertStringContainsString("`[[Home]]` is how to link it.\n```\n[x](Home)\n```\n![shot](images/a.png)\n"
            . "[GitHub](https://github.com/Open-Web-Analytics/Open-Web-Analytics)\n", $conf);

        // A 1.x link to a 2.0 page follows it to its new name.
        $this->assertStringEndsWith("\n\n[CLI](v1-Command-Line-Interface-(CLI)#run) and [[v2 settings|Settings]].\n",
            $this->page('v1/v1-Javascript-Tracker.md'));

        // 2.0: v2-X becomes X; code is left alone.
        $conf2 = $this->page('Configuration.md');
        $this->assertStringContainsString('| a | [[Settings\\|Settings#keys]] |', $conf2);
        $this->assertStringContainsString('The [tracker](Javascript-Tracker#options), the [home](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Home) and [[Upgrading-from-1.x]].', $conf2);
        $this->assertStringContainsString("<!-- BEGIN GENERATED: settings -->\n- [[Settings]]\n<!-- END GENERATED: settings -->", $conf2);
        $this->assertStringContainsString("`[[v2-Home]]` and\n```\n[[v2-Home]]\n```\n", $conf2);

        $this->assertSame("See [[Server Roles|Server-Roles]].\n", $this->page('Upgrading-from-1.x.md'));
    }

    public function testStagedBannersGoAndArchivedPagesGetOne(): void
    {

        $this->flip();

        $this->assertSame("Start with [[Configuration|Configuration]].\n", $this->page('Home.md'));
        $this->assertStringStartsWith("# Configuration\n", $this->page('Configuration.md'));
        $this->assertSame("Settings.\n", $this->page('Settings.md'));

        $banner = fn ($twin) => "> **This page documents OWA 1.x. It has been superseded by the 2.0 documentation: see $twin.**\n\n";

        // Same name; the old banner is replaced, not doubled.
        $conf = $this->page('v1/v1-Configuration.md');
        $this->assertStringStartsWith($banner('[[Configuration|Configuration]]') . 'Set [the keys]', $conf);
        $this->assertSame(1, substr_count($conf, 'This page documents'));

        $this->assertStringStartsWith($banner('[[Home|Home]]') . "Welcome.\n", $this->page('v1/v1-Home.md'));
        // The title is the new sidebar's link text, or the name with spaces.
        $this->assertSame($banner('[[Command Line|Command-Line-Interface]]') . "Run it.\n", $this->page('v1/v1-Command-Line-Interface-(CLI).md'));
        $this->assertStringStartsWith($banner('[[Javascript Tracker|Javascript-Tracker]]'), $this->page('v1/v1-Javascript-Tracker.md'));
        // A fold, and a page with no counterpart.
        $this->assertSame($banner('[[Server Roles|Server-Roles]]') . "Queues.\n", $this->page('v1/v1-Event-Queueing.md'));
        $this->assertSame($banner('[[Home|Home]]') . "Gone in 2.0.\n", $this->page('v1/v1-Old-Only.md'));
    }

    public function testSidebarsSwap(): void
    {

        $this->flip();

        $this->assertSame("- [Home](Home)\n- [Configuration](Configuration)\n- [Command Line](Command-Line-Interface)\n"
            . "### Older releases\n- [1.x documentation](v1-Home)\n", $this->page('_Sidebar.md'));
        $this->assertSame("- [Home](v1-Home)\n- [Configuration](v1-Configuration)\n", $this->page('v1/_Sidebar.md'));
    }

    public function testACollisionIsRefused(): void
    {

        self::write($this->wiki, ['v2/Settings.md' => "Unprefixed twin.\n", 'v1/v1-Home.md' => "Already here.\n"]);
        $before = $this->tree();

        [$rc, $out] = $this->cli('--from', 'v2', '--to', 'v1', '--apply');

        $this->assertSame(1, $rc, $out);
        $this->assertSame($before, $this->tree());
        $this->assertStringContainsString('name collision: v2/Settings.md and v2/v2-Settings.md would both become Settings.md', $out);
        $this->assertStringContainsString('name collision: Home.md would become v1/v1-Home.md, which already exists', $out);
        $this->assertStringContainsString('Refused: nothing was written.', $out);
    }

    public function testADanglingLinkAndAnUnsettledTwinAreProblems(): void
    {

        self::write($this->wiki, [
            'v2/v2-Goals.md' => self::banner('[[Old Only|Old-Only]]') . "See [x](v2-Missing#a).\n",
            'v2/v2-Funnels.md' => self::banner('[[Old-Only]]') . "Funnels.\n",
        ]);

        $plan = owa_wiki_flip_plan($this->wiki, 'v2', 'v1', OWA_WIKI_FLIP_FOLDS['v2']);

        $this->assertEqualsCanonicalizing([
            'v2/v2-Goals.md links to v2-Missing, which will not exist after the flip: [x](v2-Missing#a)',
            'Old-Only.md is the v1 page named by v2/v2-Funnels.md, v2/v2-Goals.md: settle it with --fold Old-Only=<page>',
        ], $plan['problems']);

        $folded = owa_wiki_flip_plan($this->wiki, 'v2', 'v1', ['Old-Only' => 'Goals']);
        $this->assertSame(['v2/v2-Goals.md links to v2-Missing, which will not exist after the flip: [x](v2-Missing#a)'], $folded['problems']);
        $this->assertContains(['v1/v1-Old-Only.md', '[[Goals|Goals]]', 'fold'], $folded['banners_added']);
    }

    public function testApplyTwiceIsRefused(): void
    {

        $this->flip();
        $after = $this->tree();

        [$rc, $out] = $this->cli('--from', 'v2', '--to', 'v1', '--apply');

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('v2/ does not exist: nothing to flip', $out);
        $this->assertSame($after, $this->tree());
    }

    public function testThreePointOhArchivesTwoPointOhAndLeavesOnePointXAlone(): void
    {

        $this->flip();

        $banner3 = fn ($twin) => "> **This is the documentation for OWA 3.0, which has not been released yet.** 3.0 pages start at [[v3 Home|v3-Home]]. For the current release, see $twin.\n\n";

        $v3 = fn ($page) => $banner3("[[$page]]") . "$page 3.\n";

        self::write($this->wiki, [
            'v3/v3-Home.md' => $banner3('[[Home]]') . "3.0 home: [[Configuration|v3-Configuration]].\n",
            'v3/v3-Configuration.md' => $banner3('[[Configuration]]')
                . "Was [[Settings]]; see [[v3 Home|v3-Home]], [1.x](v1-Home) and [[Upgrading-from-1.x]].\n",
            'v3/v3-Server-Roles.md' => $v3('Server-Roles'),
            'v3/v3-Settings.md' => $v3('Settings'),
            'v3/v3-Command-Line-Interface.md' => $v3('Command-Line-Interface'),
            'v3/Upgrading-from-2.x.md' => $banner3('[[Updating|Home]]') . "Upgrade.\n",
            'v3/_Sidebar.md' => "- [Home](v3-Home)\n- [Upgrading from 2.x](Upgrading-from-2.x)\n"
                . "- [2.x documentation](Home)\n- [1.x documentation](v1-Home)\n",
        ]);
        $v1 = $this->tree('v1');

        // v1/ is not rewritten, so its links are read as they stand after the flip.
        $this->assertSame([
            'v1/v1-Javascript-Tracker.md links to Javascript-Tracker, which will not exist after the flip: [[Javascript Tracker|Javascript-Tracker]]',
        ], owa_wiki_flip_plan($this->wiki, 'v3', 'v2')['problems']);

        self::write($this->wiki, ['v3/v3-Javascript-Tracker.md' => $v3('Javascript-Tracker')]);

        [$rc, $out] = $this->cli('--from', 'v3', '--to', 'v2', '--apply');

        $this->assertSame(0, $rc, $out);
        $this->assertSame($v1, $this->tree('v1'));
        $this->assertDirectoryDoesNotExist("$this->wiki/v3");

        $this->assertSame([
            'Command-Line-Interface.md', 'Configuration.md', 'Home.md', 'Javascript-Tracker.md', 'Server-Roles.md',
            'Settings.md', 'Upgrading-from-2.x.md', '_Sidebar.md', 'images/a.png',
            'v2/_Sidebar.md', 'v2/v2-Command-Line-Interface.md', 'v2/v2-Configuration.md', 'v2/v2-Home.md',
            'v2/v2-Javascript-Tracker.md', 'v2/v2-Server-Roles.md', 'v2/v2-Settings.md', 'v2/v2-Upgrading-from-1.x.md',
        ], array_values(array_filter(array_keys($this->tree()), fn ($p) => strpos($p, 'v1/') !== 0)));

        $this->assertSame("Was [[Settings|v2-Settings]]; see [[v3 Home|Home]], [1.x](v1-Home) and [[Upgrading-from-1.x|v2-Upgrading-from-1.x]].\n",
            $this->page('Configuration.md'));
        $this->assertSame("- [Home](Home)\n- [Upgrading from 2.x](Upgrading-from-2.x)\n"
            . "- [2.x documentation](v2-Home)\n- [1.x documentation](v1-Home)\n", $this->page('_Sidebar.md'));

        $banner2 = fn ($twin) => "> **This page documents OWA 2.x. It has been superseded by the 3.0 documentation: see $twin.**\n\n";

        $this->assertSame($banner2('[[Home|Home]]') . "Start with [[Configuration|v2-Configuration]].\n", $this->page('v2/v2-Home.md'));
        $this->assertSame($banner2('[[Settings|Settings]]') . "Settings.\n", $this->page('v2/v2-Settings.md'));
        $this->assertSame($banner2('[[Server Roles|Server-Roles]]') . "Roles.\n", $this->page('v2/v2-Server-Roles.md'));
        $this->assertSame($banner2('[[Home|Home]]') . "See [[Server Roles|v2-Server-Roles]].\n", $this->page('v2/v2-Upgrading-from-1.x.md'));
        $this->assertSame("- [Home](v2-Home)\n- [Configuration](v2-Configuration)\n- [Command Line](v2-Command-Line-Interface)\n"
            . "### Older releases\n- [1.x documentation](v1-Home)\n", $this->page('v2/_Sidebar.md'));
    }
}
