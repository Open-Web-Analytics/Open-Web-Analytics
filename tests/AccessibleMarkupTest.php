<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What a screen reader and a keyboard need from the page markup: the page's
 * language, one <h1> per page, a <main> to skip to, and a name on every form
 * control.
 */
final class AccessibleMarkupTest extends TestCase
{
    private function template(string $file): string
    {
        return (string) file_get_contents(OWA_DIR . 'modules/Base/templates/' . $file);
    }

    /**
     * A Template for the admin forms, with the helpers that need a request or
     * a database stubbed (as TemplateLatentVarTest's formSpy()).
     */
    private function formSpy(): object
    {
        return new class('base') extends \OWA\Core\Template {

            function createNonceFormField($action) { return "<!--nonce:$action-->"; }

            function makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false) {

                return 'LINK';
            }

            function getNs() { return \OWA\Core\CoreAPI::appNs(); }
        };
    }

    /** @param array<string, mixed> $vars */
    private function render(string $file, array $vars): string
    {
        $t = $this->formSpy();

        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }

        $this->assertTrue($t->set_template($file), "could not locate template $file");

        $html = (string) $t->fetch();
        $this->assertNotSame('', $html, "$file rendered nothing");

        return $html;
    }

    /**
     * The controls in some markup a person fills in, each with how it is
     * named: a <label for>, a label around it, aria-label or aria-labelledby.
     * '' for one with no name.
     *
     * @return array<string, string> the control's name attribute => its name
     */
    private function controlNames(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();

        $xp = new DOMXPath($doc);
        $out = array();

        foreach ($xp->query('//input[not(@type="hidden") and not(@type="submit")] | //select | //textarea') as $el) {

            /** @var DOMElement $el */
            $name = '';

            if ($el->getAttribute('aria-label') !== '') {
                $name = $el->getAttribute('aria-label');
            } elseif ($el->getAttribute('aria-labelledby') !== '') {
                $ref = $doc->getElementById($el->getAttribute('aria-labelledby'));
                $name = $ref ? trim($ref->textContent) : '';
            } elseif ($el->getAttribute('id') !== '') {
                $label = $xp->query('//label[@for="' . $el->getAttribute('id') . '"]')->item(0);
                $name = $label ? trim(preg_replace('/\s+/', ' ', $label->textContent)) : '';
            }

            if ($name === '') {
                for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                    if ($p->nodeName === 'label') {
                        $name = trim($p->textContent);
                        break;
                    }
                }
            }

            $out[$el->getAttribute('name')] = $name;
        }

        return $out;
    }

    public function testTheLanguageIsTheLocaleAsALanguageTag(): void
    {
        $this->assertSame('pt-BR', \OWA\Core\Strings::htmlLang('pt_BR'));
        $this->assertSame('de', \OWA\Core\Strings::htmlLang('de'));
        $this->assertSame('en', \OWA\Core\Strings::htmlLang('../../etc'), 'not a language tag: English');
        $this->assertSame('en', \OWA\Core\Strings::htmlLang(''));

        if (!defined('OWA_LOCALE')) {
            $this->assertSame('en', \OWA\Core\Strings::htmlLang());
        }
    }

    /** Every template that opens <html> says which language the page is in. */
    public function testEveryPageDeclaresItsLanguage(): void
    {
        foreach (glob(OWA_DIR . 'modules/*/templates/*.php') as $file) {

            $src = (string) file_get_contents($file);

            // The line, not up to the first '>': the attribute holds PHP.
            if (!preg_match('/^.*<html\b.*$/mi', $src, $m)) {
                continue;
            }

            $this->assertStringContainsString(
                'lang="<?php $view->out( \OWA\Core\Strings::htmlLang() ); ?>"', $m[0], basename($file));
        }
    }

    public function testTheSignedOutPagesRenderTheLanguage(): void
    {
        $html = $this->render('wrapper_public.php', array('body' => '<p>body</p>'));

        $this->assertMatchesRegularExpression('/<html lang="' . preg_quote(\OWA\Core\Strings::htmlLang(), '/') . '">/', $html);
    }

    /**
     * The page's title is its <h1>, and the content column is a <main> that
     * holds the messages and the screen but not the footer.
     */
    public function testAReportOrSettingsPageHasOneHeadingAndAMain(): void
    {
        $report = $this->template('report.php');

        $this->assertSame(1, substr_count($report, '<h1 class="owa_reportTitle">'));
        $this->assertStringContainsString('<h1 class="owa_reportTitle">', $this->template('genericAdminPage.php'));

        foreach (array('report.php', 'options_hierarchy.php') as $file) {

            $src = $this->template($file);
            $open = strpos($src, '<main id="owa_main">');
            $close = strpos($src, '</main>');

            $this->assertNotFalse($open, "$file has no <main id=\"owa_main\">");
            $this->assertNotFalse($close);
            $this->assertLessThan(strpos($src, 'View::BANNER_SLOT'), $open, "$file: the messages are outside <main>");
            $this->assertGreaterThan(strpos($src, '$view->subview'), $close, "$file: the screen is outside <main>");
            $this->assertLessThan(strpos($src, 'View::FOOTER_SLOT'), $close, "$file: the footer is inside <main>");
        }
    }

    /** A settings screen's headline is the page's <h1>. */
    public function testEverySettingsHeadlineIsAnH1(): void
    {
        foreach (glob(OWA_DIR . 'modules/*/templates/*.php') as $file) {

            $src = (string) file_get_contents($file);

            if (strpos($src, 'panel_headline') === false) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/<(?!h1\b)[a-z]+[^>]*class="panel_headline"/i', $src, basename($file));
            $this->assertSame(1, substr_count($src, '<h1 class="panel_headline">'), basename($file));
        }
    }

    /** The skip link comes before the header, and only on a page with somewhere to skip to. */
    public function testTheSkipLinkLeadsTheDefaultWrapper(): void
    {
        $src = $this->template('wrapper_default.php');

        $link = strpos($src, '<a class="owa_skipLink" href="#owa_main">');

        $this->assertNotFalse($link);
        $this->assertLessThan(strpos($src, 'echo $owa_header;'), $link);
        $this->assertStringContainsString("strpos( \$owa_body, 'id=\"owa_main\"' ) !== false", $src);

        $css = (string) file_get_contents(OWA_DIR . 'modules/Base/css/owa.css');
        $this->assertStringContainsString('.owa .owa_skipLink:focus {', $css, 'it is shown when it has the focus');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, string>}> */
    public static function forms(): array
    {
        $ns = \OWA\Core\CoreAPI::appNs();

        $profile = array(
            'user_id' => 'someone@example.test', 'real_name' => 'Someone',
            'email_address' => 'someone@example.test', 'role' => 'viewer',
            'my_profile_error' => '', 'my_profile_saved' => false,
            'siteId' => 'owa-e2e', 'min_password_length' => 6,
        );

        $goal = array(
            'headline' => 'New Goal Event Definition', 'propertyId' => '7000000000000001',
            'goalEventDefinition' => [], 'goalEventDefinitionId' => '',
            'conditionProperties' => [ [ 'name' => 'page_path', 'label' => 'Page path',
                'description' => 'The path of the page.', 'carried' => true ] ],
            'conditionVocabulary' => [ 'page_view' => [ [ 'name' => 'page_path', 'label' => 'Page path',
                'description' => 'The path of the page.', 'carried' => true ] ], 'click' => [] ],
            'savedProperties' => [], 'triggerEvent' => 'page_view',
            'triggerEvents' => [ 'page_view', 'click' ],
            'customTriggerEvents' => [ 'newsletter_signup' ], 'customTriggerEvent' => '',
            'funnelSteps' => [], 'conditions' => [], 'validation_errors' => [],
        );

        $site = array(
            'headline' => 'Profile', 'action' => 'base.sitesAdd', 'edit' => false,
            'site' => [], 'config' => [], 'validation_errors' => [], 'properties' => [],
        );

        return array(
            'my_profile' => array('my_profile.php', $profile + array('may_edit_email' => true), array(
                'real_name' => 'Name', 'email_address' => 'Email address',
                'current_password' => 'Current password', 'new_password' => 'New password',
                'new_password2' => 'Repeat new password')),
            'my_profile, email not editable' => array('my_profile.php', $profile + array('may_edit_email' => false), array(
                'real_name' => 'Name', 'current_password' => 'Current password',
                'new_password' => 'New password', 'new_password2' => 'Repeat new password')),
            'organization_profile' => array('organization_profile.php', array('organization' => array('name' => 'Org')), array(
                $ns . 'name' => 'Name')),
            'sites_addoredit, add' => array('sites_addoredit.php', $site, array(
                $ns . 'propertyId' => 'Property:', $ns . 'name' => 'New Property Name:',
                $ns . 'streamType' => 'This Profile observes:', $ns . 'domain' => 'Domain:',
                $ns . 'appId' => 'App ID:', $ns . 'description' => 'Description:')),
            'sites_addoredit, edit' => array('sites_addoredit.php', array_merge($site, array(
                'edit' => true, 'action' => 'base.sitesEdit', 'propertyName' => 'A Property',
                'site' => array('site_id' => 'x1', 'name' => 'Main', 'domain' => 'example.test'))), array(
                $ns . 'name' => 'Profile Name:', $ns . 'description' => 'Description:')),
            'users_addoredit, add' => array('users_addoredit.php', array(
                'headline' => 'Add user', 'action' => 'base.usersAdd', 'edit' => false,
                'isAdmin' => false, 'roles' => ['admin', 'viewer'], 'user' => [],
            ), array(
                $ns . 'user_id' => 'User Name', $ns . 'real_name' => 'Real Name',
                $ns . 'role' => 'Role', $ns . 'email_address' => 'E-mail Address')),
            'goal_event_definition_edit, add' => array('goal_event_definition_edit.php', $goal, array(
                $ns . 'name' => 'Name', $ns . 'value' => 'Value optional',
                $ns . 'countMode' => 'How often', $ns . 'isActive' => 'Status')),
        );
    }

    /**
     * Each control is named by the words beside it on the screen.
     *
     * @dataProvider forms
     * @param array<string, string> $expected  control name => its accessible name
     */
    public function testEveryControlIsNamed(string $template, array $vars, array $expected): void
    {
        $names = $this->controlNames($this->render($template, $vars));

        foreach ($expected as $control => $name) {
            $this->assertArrayHasKey($control, $names, "$template has no control $control");
            $this->assertSame($name, $names[$control], "$template: $control");
        }

        $this->assertSame(array(), array_keys(array_filter($names, function ($n) { return $n === ''; })),
            "$template has controls with no name");
    }

    /** The three password boxes share one title, so the title names their group. */
    public function testThePasswordBoxesAreAGroupUnderTheirTitle(): void
    {
        $html = $this->render('my_profile.php', array(
            'user_id' => 'u', 'real_name' => '', 'email_address' => '', 'role' => 'viewer',
            'may_edit_email' => true, 'my_profile_error' => '', 'my_profile_saved' => false,
            'siteId' => '', 'min_password_length' => 6,
        ));

        $this->assertMatchesRegularExpression(
            '#<div class="owa_passwordFields" role="group" aria-labelledby="owa_myProfilePasswordTitle"#', $html);
        $this->assertStringContainsString('<div class="title" id="owa_myProfilePasswordTitle">Change password</div>', $html);
    }

    /** A label keeps the block layout of the div it replaced. */
    public function testALabelIsLaidOutAsTheTitleItReplaced(): void
    {
        $css = (string) file_get_contents(OWA_DIR . 'modules/Base/css/owa.css');

        $this->assertMatchesRegularExpression(
            '/\.owa \.setting label\.title,\s*\.owa label\.inline_h3 \{\s*display: block;/', $css);
    }
}
