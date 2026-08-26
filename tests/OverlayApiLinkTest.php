<?php

use PHPUnit\Framework\TestCase;

/**
 * The overlay API link must carry the report's state, not just its token.
 *
 * Template::makeOverlayApiLink() replaced two makeApiLink( $params, true, true )
 * calls -- in report_document.php (heatmap) and report_domstreams.php (player).
 * The middle argument is add_state, and it is what merges caller_params
 * ['link_state'] (siteId) and the time period onto the link. The replacement
 * dropped it, and both overlays lost their siteId.
 *
 * That failed asymmetrically, which is why it needs pinning here rather than
 * being left to the e2e:
 *
 *   - the player's controller declares siteId required, so it answered 422 and
 *     the overlay was visibly broken;
 *   - the heatmap's reports route does not, so it answered *200* for a query
 *     with no site filter and a defaulted period. Wrong clicks, reported as
 *     success -- no status code, no console error, and nothing in the DOM to
 *     tell a passing overlay from one plotting another site's data.
 *
 * The cross-origin e2e cannot cover this: it builds its request URL directly,
 * so it asserts what the API does with a siteId rather than whether the admin
 * side puts one there. This is the seam where that decision is actually made.
 */
final class OverlayApiLinkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * A template positioned the way a report template is when it builds the
     * link: a link_state carrying the site being reported on.
     */
    private function reportTemplate(string $siteId = 'site-under-report'): \OWA\Core\Template
    {
        $t = new \OWA\Core\Template();
        $t->caller_params['link_state'] = ['siteId' => $siteId];
        $t->config['rest_api_url']      = 'https://owa.example.com/api/index.php';

        return $t;
    }

    private function heatmapLink(\OWA\Core\Template $t): string
    {
        /*
         * The real heatmap query: clicks grouped by coordinate, constrained on
         * the page. The token pins `constraints`, because that is the parameter
         * carrying which page the link is for -- there is no clicks report and
         * no document_id any more.
         */
        return $t->makeOverlayApiLink([
            'metrics'     => 'domClicks',
            'dimensions'  => 'clickX,clickY',
            'constraints' => 'pagePath==' . urlencode('/pricing'),
            'module'      => 'base',
            'version'     => 'v1',
            'do'          => 'reports',
        ], 'constraints');
    }

    public function testTheHeatmapLinkCarriesTheSiteBeingReportedOn(): void
    {
        $link = $this->heatmapLink($this->reportTemplate());

        // Without this the reports route still answers 200, for the wrong site.
        $this->assertStringContainsString('siteId=site-under-report', $link);
    }

    public function testThePlayerLinkCarriesTheSiteBeingReportedOn(): void
    {
        $t = $this->reportTemplate();

        $link = $t->makeOverlayApiLink([
            'domstream_guid' => '4921417228',
            'module'         => 'domstream',
            'version'        => 'v1',
            'do'             => 'domstreams',
        ], 'domstream_guid');

        // DomstreamsRestController::validate() declares siteId required.
        $this->assertStringContainsString('siteId=', $link);
        $this->assertStringContainsString('site-under-report', $link);
    }

    /**
     * State is merged, never substituted for the caller's own parameters -- a
     * link that carried siteId but lost the resource it names would satisfy the
     * assertions above while being useless, and would not match its token.
     */
    public function testStateIsAddedWithoutDisplacingTheCallersParams(): void
    {
        $link = $this->heatmapLink($this->reportTemplate());

        $this->assertStringContainsString('metrics=domClicks', $link);
        $this->assertStringContainsString('constraints=', $link);
        $this->assertStringContainsString('do=reports', $link);
        $this->assertStringContainsString('overlayToken=', $link);
    }

    /**
     * The token on the link must be valid for that same link: minted for the
     * link's action, and for the resource the link actually names.
     */
    public function testTheTokenOnTheLinkMatchesTheLinkItIsOn(): void
    {
        $link = $this->heatmapLink($this->reportTemplate());

        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        $token = $q['owa_overlayToken'] ?? ($q['overlayToken'] ?? '');
        $this->assertNotSame('', $token, 'the link carried no overlay token');

        $permitted = \OWA\Core\OverlayToken::permits(
            $token,
            'reports',
            static fn($name) => $name === 'constraints'
                ? 'pagePath==' . urlencode('/pricing')
                : ''
        );

        $this->assertTrue($permitted, 'the minted token does not permit its own link');
    }
}
