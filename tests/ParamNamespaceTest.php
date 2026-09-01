<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Which spellings of a request param OWA reads, and where the `owa_` prefix
 * still has to be there.
 *
 * The prefix was ONE setting doing three unrelated jobs:
 *
 *   1. cookie names, in a jar shared with the tracked page
 *   2. attribution params a customer puts on their own URLs (owa_source,
 *      owa_campaign) and the cross-domain owa_state handoff
 *   3. OWA's own admin and reporting URLs and form fields
 *
 * Only (3) has nothing to collide with -- OWA owns that whole query string --
 * so only (3) drops the prefix. It is now the 'app_ns' setting, empty, while
 * 'ns' stays 'owa_' and keeps doing (1) and (2). Conflating them is the trap
 * this file exists to catch: RequestContainer ran cookies, $_SESSION and query
 * params through the SAME stripParams() call, so a change aimed at links landed
 * on the cookie jar unless the surfaces were held apart deliberately. The param
 * path has its own resolution loop now; cookies and $_SESSION still go through
 * stripParams(), and still require the prefix.
 *
 * The other half is that stripParams() does not merely rename keys, it FILTERS:
 * anything without the prefix is dropped -- and the param loop keeps that
 * filter. It is what stops a host
 * application's query string reaching OWA's dispatcher when OWA is embedded in
 * someone else's request. So dropping the prefix cannot mean "accept anything"
 * -- it means accepting bare names only for the entry points that own their
 * query string, identified by instance_role.
 */
final class ParamNamespaceTest extends TestCase
{
    /** @var array */
    private $get;
    /** @var array */
    private $post;
    /** @var array */
    private $server;
    /** @var array */
    private $cookie;

    protected function setUp(): void
    {
        $this->get    = $_GET;
        $this->post   = $_POST;
        $this->server = $_SERVER;
        $this->cookie = $_COOKIE;

        $_GET    = array();
        $_POST   = array();
        $_COOKIE = array();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST']      = 'owa.example.test';
        $_SERVER['SERVER_PORT']    = 80;
        $_SERVER['REQUEST_URI']    = '/owa/index.php';
        unset($_SERVER['HTTP_COOKIE']);
    }

    protected function tearDown(): void
    {
        $_GET    = $this->get;
        $_POST   = $this->post;
        $_SERVER = $this->server;
        $_COOKIE = $this->cookie;

    }

    /**
     * A fresh container built from the current superglobals. NOT the singleton:
     * the whole point is to build several, with different params, in one process.
     */
    private function container(): \OWA\Core\RequestContainer
    {
        return new \OWA\Core\RequestContainer();
    }

    /* ---------------------------------------------------------------- params */

    /**
     * The change itself. OWA's own admin URLs read 'do=', not 'owa_do='.
     */
    public function testBareParamsAreReadOnOwaSOwnEndpoints(): void
    {
        $_GET = array('do' => 'base.reportingHome', 'siteId' => 'abc123');

        $r = $this->container();

        $this->assertSame('base.reportingHome', $r->getParam('do'));
        $this->assertSame('abc123', $r->getParam('siteId'));
    }

    /**
     * And the reason this is additive rather than a break: every bookmark,
     * saved report link, e2e spec and third-party API caller in existence
     * spells them with the prefix. Both are read.
     */
    public function testPrefixedParamsAreStillRead(): void
    {
        $_GET = array('owa_do' => 'base.reportingHome', 'owa_siteId' => 'abc123');

        $r = $this->container();

        $this->assertSame('base.reportingHome', $r->getParam('do'));
        $this->assertSame('abc123', $r->getParam('siteId'));
    }

    /**
     * On a collision the explicit, namespaced spelling wins. It is the older
     * contract, and preferring it means a stray bare param can never displace
     * one a caller deliberately namespaced.
     */
    public function testThePrefixedSpellingWinsACollision(): void
    {
        $_GET = array('do' => 'base.bare', 'owa_do' => 'base.prefixed');

        $r = $this->container();

        $this->assertSame('base.prefixed', $r->getParam('do'));
    }

    /**
     * ...in EITHER arrival order. The case above resolves itself, because the
     * namespaced param simply overwrites the bare one it finds. This is the one
     * that needs the guard: the namespaced spelling arrives FIRST, and the bare
     * one that follows must not be allowed to overwrite it.
     */
    public function testThePrefixedSpellingWinsWhenItArrivesFirst(): void
    {
        $_GET = array('owa_do' => 'base.prefixed', 'do' => 'base.bare');

        $r = $this->container();

        $this->assertSame('base.prefixed', $r->getParam('do'));
    }

    /**
     * 'do' and 'action' choose which controller runs, so both spellings are run
     * through the include filter. Filtering only the prefixed one would leave
     * the bare one an unfiltered path into the same dispatch.
     */
    public function testBareDoAndActionAreFilteredForIncludeExploits(): void
    {
        $_GET = array('do' => '../../../../etc/passwd');

        $r = $this->container();

        $this->assertStringNotContainsString('..', (string) $r->getParam('do'));
        $this->assertStringNotContainsString('/', (string) $r->getParam('do'));
    }

    /**
     * Reserved-word rekeying is unchanged, so the bare spelling behaves exactly
     * as the prefixed one always did: 'action' is what JavaScript callers send,
     * 'do' is what OWA reads internally.
     */
    public function testBareActionIsRekeyedLikeThePrefixedForm(): void
    {
        $_GET = array('action' => 'base.login');

        $r = $this->container();

        $this->assertSame('base.login', $r->getParam('do'));
    }

    /**
     * THE ORDER CONTRACT. Every admin form POST carries both spellings of the
     * action: 'do' in the query string the form posts back to, and 'action' in
     * the form body. reserved_words rekeys 'action' to 'do' AFTER the params are
     * resolved, so the two land on the same key and the one sitting LATER in the
     * array wins -- which is the form's, because $_POST is merged after $_GET.
     *
     * Resolving the namespaced and bare spellings into two arrays and merging
     * them regroups the params and inverts that, so the login form's own POST
     * re-rendered the login form instead of logging anyone in. Nothing in the
     * unit suite noticed; the e2e login spec did.
     */
    public function testAFormPostsActionBeatsTheQueryStringsDo(): void
    {
        // exactly what the login form sends: the page URL still says
        // do=base.loginForm, the submitted body says action=base.login.
        $_GET  = array('owa_do' => 'base.loginForm', 'owa_go' => '');
        $_POST = array('action' => 'base.login', 'user_id' => 'someone', 'password' => 'x');
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $r = $this->container();

        $this->assertSame(
            'base.login',
            $r->getParam('do'),
            "the form's action must beat the query string's do, or no admin form can submit"
        );
    }

    /**
     * The same contract in its pre-existing, fully namespaced form -- so the
     * behaviour is pinned independently of which spelling a caller uses.
     */
    public function testTheOrderContractHoldsForThePrefixedSpellingToo(): void
    {
        $_GET  = array('owa_do' => 'base.loginForm');
        $_POST = array('owa_action' => 'base.login');
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $r = $this->container();

        $this->assertSame('base.login', $r->getParam('do'));
    }

    /**
     * THE BEACON. Its query string is written by the tracker and read by
     * log.php, and nothing else contributes a param to it -- so there is
     * nothing for a prefix to protect against, and the tracker stopped adding
     * one (~4 bytes per property, on the highest-volume request OWA makes).
     *
     * Trackers cached on customer sites keep sending the namespaced spelling
     * for as long as their cache lives, so both have to land on the same
     * property. This is the assertion that lets those age out safely.
     */
    public function testTheBeaconIsReadUnderEitherSpelling(): void
    {
        $bare = array(
            'event_type' => 'base.page_request',
            'site_id'    => 'beacon-site',
            'page_url'   => 'https://site.example/p',
        );

        $_GET = $bare;
        $r = $this->container();

        foreach ( $bare as $name => $value ) {
            $this->assertSame($value, $r->getParam($name), "bare beacon param '$name'");
        }

        // ...and the same beacon from an older, cached tracker.
        $_GET = array();
        foreach ( $bare as $name => $value ) {
            $_GET[ 'owa_' . $name ] = $value;
        }
        $r = $this->container();

        foreach ( $bare as $name => $value ) {
            $this->assertSame($value, $r->getParam($name), "namespaced beacon param '$name'");
        }
    }

    /**
     * The bracket syntax the tracker uses for arrays of objects (line items)
     * survives either spelling -- PHP's $_GET parser builds the nested array
     * from the NAME, so the namespace has to come off without disturbing it.
     */
    public function testBeaconBracketParamsSurviveTheStrip(): void
    {
        $_GET = array( 'owa_ct_line_items' => array( 0 => array( 'li_sku' => 'SKU-1' ) ) );

        $r = $this->container();

        $this->assertSame( array( 0 => array( 'li_sku' => 'SKU-1' ) ), $r->getParam('ct_line_items') );
    }

    /* --------------------------------------------------------------- cookies */

    /**
     * The trap. Cookies live in a jar shared with the tracked page, so the
     * prefix is what tells OWA's state apart from everyone else's -- and it
     * goes through the same stripParams() the params do.
     */
    public function testCookiesStillRequireThePrefix(): void
    {
        $_SERVER['HTTP_COOKIE'] = 'owa_v=visitor-state; sessionid=someone-elses; PHPSESSID=abc';

        $r = $this->container();

        $this->assertArrayHasKey('v', $r->owa_cookies);
        $this->assertArrayNotHasKey('sessionid', $r->owa_cookies);
        $this->assertArrayNotHasKey('PHPSESSID', $r->owa_cookies);
    }

    /**
     * The namespace has to be a PREFIX. stripParams() used to test strstr() --
     * present ANYWHERE -- and then chop four characters off the front anyway,
     * so a host page's 'my_owa_setting' was both claimed as OWA's and mangled
     * into a key nobody sent.
     */
    public function testACookieThatMerelyContainsTheNamespaceIsNotOwaS(): void
    {
        $_SERVER['HTTP_COOKIE'] = 'my_owa_setting=not-ours; owa_s=ours';

        $r = $this->container();

        $this->assertSame(array('s'), array_keys($r->owa_cookies));
    }

    /**
     * A single cookie is the only way to reach the $_COOKIE fallback -- the
     * raw-header path above needs a ';' -- and that branch used to assign to an
     * undeclared local, so the cookies went out of scope with the loop and OWA
     * saw a stateless visitor.
     */
    public function testASingleCookieIsNotDropped(): void
    {
        $_SERVER['HTTP_COOKIE'] = 'owa_v=visitor-state';
        $_COOKIE = array('owa_v' => 'visitor-state');

        $r = $this->container();

        $this->assertArrayHasKey('v', $r->owa_cookies);
    }

    /* ------------------------------------------------------------ stripParams */

    /**
     * Pinned directly, because $_SESSION goes through the same call and there
     * is no other assertion covering that surface.
     */
    public function testStripParamsFiltersToPrefixedNamesOnly(): void
    {
        $stripped = \OWA\Core\Lib::stripParams(
            array('owa_do' => 'x', 'do' => 'y', 'my_owa_thing' => 'z', 'owa_' => 'nameless'),
            'owa_'
        );

        $this->assertSame(array('do' => 'x'), $stripped);
    }

    /* -------------------------------------------------------- emitted markup */

    /**
     * What OWA EMITS. Every admin form field name goes through this one helper,
     * which is why the whole form surface moves together.
     */
    public function testGetNsReturnsTheAppNamespace(): void
    {
        $t = new \OWA\Core\Template('base');

        $this->assertSame('', $t->getNs());
        $this->assertSame(
            'owa_',
            \OWA\Core\CoreAPI::getSetting('base', 'ns'),
            'the wire namespace must not have moved with it'
        );
    }

    /**
     * The round trip that matters for forms: the field name a template emits
     * has to be the name the server reads back. A nonce that arrives under a
     * name nothing looks up fails closed, and every admin POST breaks.
     */
    public function testTheNonceFieldNameIsWhatTheServerReadsBack(): void
    {
        $t = new \OWA\Core\Template('base');

        $field = $t->createNonceFormField('base.sitesDelete');

        $this->assertMatchesRegularExpression('/name="' . preg_quote($t->getNs(), '/') . 'nonce"/', $field);

        // and that name, sent as a param, resolves to the 'nonce' OWA reads.
        preg_match('/name="([^"]+)"/', $field, $m);
        $_GET = array($m[1] => 'the-nonce');

        $r = $this->container();

        $this->assertSame('the-nonce', $r->getParam('nonce'));
    }

    /**
     * Link building follows the same namespace as the form fields, so a link
     * OWA emits is readable by the container OWA builds.
     */
    public function testMakeParamStringEmitsTheAppNamespace(): void
    {
        $t = new \OWA\Core\Template('base');

        $qs = $t->makeParamString(array('do' => 'base.reportingHome', 'siteId' => 'abc'), false);

        $this->assertSame('do=base.reportingHome&siteId=abc', $qs);

        parse_str($qs, $parsed);
        $_GET = $parsed;

        $r = $this->container();

        $this->assertSame('base.reportingHome', $r->getParam('do'));
        $this->assertSame('abc', $r->getParam('siteId'));
    }
}
