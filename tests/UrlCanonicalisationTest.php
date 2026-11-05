<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\V2Event;

/**
 * What v2 does to a URL, and what it deliberately does not.
 *
 * THE EVIDENCE IS NOT EDITED. page_location is stored exactly as it arrived,
 * because it is what a corrected parse gets re-applied to -- v1 needed
 * keepCompleteUrl() to stash the URL before its own canonicaliser ate it. The
 * READINGS --
 * page_path, page_query, host -- are what reports group by, and those are
 * canonicalised, because a reading that varies where the page does not is a
 * broken report.
 *
 * The fragment is not here: it is stripped in the TRACKER and never reaches the
 * server at all. See tests/js/UrlFragments.test.js.
 */
final class UrlCanonicalisationTest extends TestCase
{
    public function testADirectoryIndexPageIsCollapsed(): void
    {
        // The whole point: one page, one row in a page report.
        $this->assertSame('/store', V2Event::canonicalPath('/store/index.html', ['index.html']));
        $this->assertSame('/store', V2Event::canonicalPath('/store/', ['index.html']));
        $this->assertSame('/store', V2Event::canonicalPath('/store', ['index.html']));
    }

    /** Every name in the default list folds, with nothing configured. */
    public function testEachDefaultIndexPageIsCollapsed(): void
    {
        $this->assertSame(['index.html', 'index.htm', 'index.php', 'default.htm', 'default.html',
            'default.aspx', 'default.asp'], V2Event::DIRECTORY_INDEX_PAGES);

        foreach (V2Event::DIRECTORY_INDEX_PAGES as $name) {
            $this->assertSame('/store', V2Event::canonicalPath('/store/' . $name, V2Event::DIRECTORY_INDEX_PAGES), $name);
        }
    }

    /** The setting's default is that list, so an install that sets nothing folds them. */
    public function testTheSettingsDefaultIsTheList(): void
    {
        $declared = (include OWA_DIR . 'modules/Base/settings.php')['settings']['directory_index_pages'] ?? [];

        $this->assertSame(V2Event::DIRECTORY_INDEX_PAGES, $declared['default'] ?? null);
        $this->assertSame(['install'], $declared['scopes'] ?? null, 'install-wide, not per Profile');
    }

    /** The name is compared without case; the rest of the path keeps its own. */
    public function testTheIndexPageIsComparedWithoutCase(): void
    {
        $this->assertSame('/Store', V2Event::canonicalPath('/Store/Default.aspx', V2Event::DIRECTORY_INDEX_PAGES));
        $this->assertSame('/store', V2Event::canonicalPath('/store/INDEX.HTML', ['index.html']));
        $this->assertSame('/store', V2Event::canonicalPath('/store/index.html', ['Index.HTML']));
    }

    /** Only the last segment, and only the whole of it. */
    public function testAMiddleSegmentIsUntouched(): void
    {
        $this->assertSame('/index.php/products',
            V2Event::canonicalPath('/index.php/products', V2Event::DIRECTORY_INDEX_PAGES));
        $this->assertSame('/store/myindex.html',
            V2Event::canonicalPath('/store/myindex.html', V2Event::DIRECTORY_INDEX_PAGES));
        $this->assertSame('/store/index.html.bak',
            V2Event::canonicalPath('/store/index.html.bak', V2Event::DIRECTORY_INDEX_PAGES));
    }

    public function testACustomListIsUsed(): void
    {
        $this->assertSame('/store', V2Event::canonicalPath('/store/home.cfm', ['home.cfm']));
        $this->assertSame('/store/index.html', V2Event::canonicalPath('/store/index.html', ['home.cfm']),
            'a list that does not name index.html does not fold it');

        // A config file is likeliest to write it as one string.
        $this->assertSame('/store', V2Event::canonicalPath('/store/home.cfm', 'index.html, home.cfm'));
    }

    public function testAnEmptyListFoldsNothingButTheTrailingSlash(): void
    {
        $this->assertSame('/store/index.html', V2Event::canonicalPath('/store/index.html', []));
        $this->assertSame('/store/index.html', V2Event::canonicalPath('/store/index.html', ''));
        $this->assertSame('/store/index.html', V2Event::canonicalPath('/store/index.html', [' ', '']));
        $this->assertSame('/store', V2Event::canonicalPath('/store/', []));
    }

    public function testATrailingSlashIsCollapsedWithoutAnIndexPage(): void
    {
        $this->assertSame('/store', V2Event::canonicalPath('/store/', []));
        $this->assertSame('/a/b/c', V2Event::canonicalPath('/a/b/c///', []));
        $this->assertSame('/store', V2Event::canonicalPath('/store/', V2Event::DIRECTORY_INDEX_PAGES));
    }

    /**
     * Ingest folds with the install's list. Set here for this request, so the
     * case does not depend on what the install stored.
     */
    public function testIngestFoldsTheIndexPage(): void
    {
        $before = \OWA\Core\CoreAPI::getSetting('base', 'directory_index_pages');

        \OWA\Core\CoreAPI::setSetting('base', 'directory_index_pages', V2Event::DIRECTORY_INDEX_PAGES);

        try {
            $event = new \OWA\Module\Base\Classes\Event();
            $event->setProperties(['site_id' => 'canonical-path-site',
                'page_location' => 'https://canonical.test/store/index.html?x=1']);

            $this->assertSame('/store',
                \OWA\Module\Base\Classes\TrackingEventHelpers::derivePagePath(null, $event));

            \OWA\Core\CoreAPI::setSetting('base', 'directory_index_pages', ['home.cfm']);

            $this->assertSame('/store/index.html',
                \OWA\Module\Base\Classes\TrackingEventHelpers::derivePagePath(null, $event),
                'the list is read, not the default');

        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'directory_index_pages', $before);
        }
    }

    /**
     * The root keeps its slash.
     *
     * Stripping it leaves '', which groups the home page under a different
     * value from every other page -- and an empty string reads as absence
     * everywhere else in this schema.
     */
    public function testTheRootIsStillASlash(): void
    {
        $this->assertSame('/', V2Event::canonicalPath('/', ['index.html']));
        $this->assertSame('/', V2Event::canonicalPath('/', []));
        $this->assertSame('/', V2Event::canonicalPath('/index.html', ['index.html']));
    }

    /**
     * Paths are case-sensitive, so two that differ in case are two pages.
     *
     * Folding them would merge rows a site may deliberately keep apart, and it
     * is not recoverable afterwards.
     */
    public function testThePathIsNotLowercased(): void
    {
        $this->assertSame('/About', V2Event::canonicalPath('/About', []));
        $this->assertSame('/About', V2Event::canonicalPath('/About/index.html', V2Event::DIRECTORY_INDEX_PAGES));
    }

    public function testNothingIsInventedFromNothing(): void
    {
        $this->assertNull(V2Event::canonicalPath(null, ['index.html']));
        $this->assertSame('', V2Event::canonicalPath('', ['index.html']));
    }

    /** An index page only matches as the last segment, not anywhere in the path. */
    public function testTheIndexPageIsTheLastSegmentNotASubstring(): void
    {
        $this->assertSame('/index.html/deeper',
            V2Event::canonicalPath('/index.html/deeper', ['index.html']));
    }

    public function testNamedParametersAreRemoved(): void
    {
        $this->assertSame('keep=1',
            V2Event::filterQuery('owa_state=abc&keep=1', ['owa_state']));

        $this->assertSame('keep=1',
            V2Event::filterQuery('keep=1&owa_state=abc', ['owa_state']));

        $this->assertSame('a=1&b=2',
            V2Event::filterQuery('a=1&owa_ad=x&b=2', ['owa_ad']));
    }

    /** Everything stripped leaves NULL, not an empty string or a dangling '?'. */
    public function testAQueryWithNothingLeftIsNull(): void
    {
        $this->assertNull(V2Event::filterQuery('owa_state=abc', ['owa_state']));
        $this->assertNull(V2Event::filterQuery('owa_state=abc&owa_ad=y', ['owa_state', 'owa_ad']));
    }

    /**
     * REBUILT FROM PARTS, WHICH IS THE WHOLE DIFFERENCE FROM v1.
     *
     * v1 strips a parameter with `#\?name=.*$|&name=.*$|name=.*&#msiU` over the
     * whole URL. That matches the name anywhere -- inside another parameter's
     * value, inside a path segment -- and then takes everything after it. These
     * are the cases where the two disagree, and v1 is wrong in every one.
     */
    public function testANameInsideAnotherValueIsNotAParameter(): void
    {
        $this->assertSame('redirect=/owa_state=no&keep=1',
            V2Event::filterQuery('redirect=/owa_state=no&keep=1', ['owa_state']),
            'the name appears inside a VALUE and is not a parameter of its own');
    }

    public function testAParameterWhoseNameMerelyContainsAnotherIsKept(): void
    {
        $this->assertSame('owa_state_backup=1',
            V2Event::filterQuery('owa_state_backup=1', ['owa_state']));

        $this->assertSame('my_owa_state=1',
            V2Event::filterQuery('my_owa_state=1', ['owa_state']));
    }

    public function testEverythingAfterAMatchIsNotAlsoRemoved(): void
    {
        $this->assertSame('a=1&b=2&c=3',
            V2Event::filterQuery('a=1&owa_state=x&b=2&c=3', ['owa_state']),
            'v1 takes the rest of the string with it; a list does not work that way');
    }

    /**
     * What survives keeps its original encoding.
     *
     * The pairs are never decoded and re-encoded, so a value written with %20
     * comes back with %20 and one written with + comes back with +. Rewriting
     * them would change what the site's own logs say the URL was.
     */
    public function testSurvivingParametersAreNotReEncoded(): void
    {
        $this->assertSame('q=two%20words&r=two+words',
            V2Event::filterQuery('q=two%20words&owa_ad=x&r=two+words', ['owa_ad']));
    }

    /** The name is compared decoded, because an operator types it undecoded. */
    public function testAnEncodedParameterNameStillMatches(): void
    {
        $this->assertSame('keep=1',
            V2Event::filterQuery('owa%5Fstate=abc&keep=1', ['owa_state']));
    }

    public function testAValuelessParameterIsMatchedOnItsName(): void
    {
        $this->assertSame('keep=1', V2Event::filterQuery('owa_overlay&keep=1', ['owa_overlay']));
    }

    public function testAnEmptyFilterListChangesNothing(): void
    {
        $this->assertSame('a=1&b=2', V2Event::filterQuery('a=1&b=2', []));
        $this->assertSame('a=1&b=2', V2Event::filterQuery('a=1&b=2', ['', '  ']));
    }

    public function testAnAbsentQueryStaysAbsent(): void
    {
        $this->assertNull(V2Event::filterQuery(null, ['owa_state']));
        $this->assertNull(V2Event::filterQuery('', ['owa_state']));
    }

    /**
     * utm_* SURVIVES, because it is the site's tagging and not ours.
     *
     * v1 keeps it. Removing it would also remove the evidence a
     * stale tracker's campaign is re-parsed from.
     */
    public function testTheSitesOwnCampaignTaggingIsKept(): void
    {
        $this->assertSame('utm_source=newsletter&utm_medium=email',
            V2Event::filterQuery(
                'utm_source=newsletter&owa_state=x&utm_medium=email', ['owa_state']));
    }
}
