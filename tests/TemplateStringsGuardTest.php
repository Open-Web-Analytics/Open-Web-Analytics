<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/UserFacingStrings.php';

/**
 * Every template's user-facing English goes through the catalogue: no text
 * node, user-facing attribute or echoed string literal is English written
 * into the page. UserFacingStrings says what counts.
 *
 * Exceptions are ALLOWED, each with its reason; there is no baseline of
 * existing findings.
 */
final class TemplateStringsGuardTest extends TestCase
{
    /** Template => why its findings are not language a person reads. */
    private const ALLOWED = [
        'modules/Base/templates/config_dom.php' => 'JavaScript, not HTML: head.php includes it inside a <script> element',
    ];

    /** The checkout, without booting OWA: this test reads files only. */
    private static function root(): string
    {
        return dirname(__DIR__) . '/';
    }

    /** @dataProvider templates */
    public function testTheTemplateWritesNoEnglishOutsideTheCatalogue(string $file): void
    {
        $found = array_map(function ($f) use ($file) {
            return UserFacingStrings::describe($file, $f);
        }, UserFacingStrings::inTemplate((string) file_get_contents(self::root() . $file)));

        $this->assertSame([], $found, "untranslated English in $file:\n" . implode("\n", $found));
    }

    public static function templates(): array
    {
        $out = [];

        foreach (glob(self::root() . 'modules/*/templates/*.php') as $path) {
            $file = substr($path, strlen(self::root()));

            if (!isset(self::ALLOWED[$file])) {
                $out[$file] = [$file];
            }
        }

        return $out;
    }

    public function testEveryAllowedTemplateExists(): void
    {
        foreach (array_keys(self::ALLOWED) as $file) {
            $this->assertFileExists(self::root() . $file);
        }
    }

    /**
     * What a bad template looks like, each caught with the line it is on.
     *
     * @dataProvider untranslated
     */
    public function testUntranslatedEnglishIsFound(string $template, int $line, string $what): void
    {
        $found = UserFacingStrings::inTemplate($template);

        $this->assertCount(1, $found, print_r($found, true));
        $this->assertSame($line, $found[0]['line']);
        $this->assertSame($what, $found[0]['what']);
    }

    public static function untranslated(): array
    {
        return [
            'a text node'                 => ["<div>\n<p>Save your changes.</p>\n</div>", 2, 'text'],
            'text beside PHP output'      => ["<p>Step <?php echo \$n; ?></p>", 1, 'text'],
            'a title'                     => ["<a href=\"#\"\n   title=\"Edit this report\"><i></i></a>", 2, 'title attribute'],
            'a placeholder'               => ['<input type="text" placeholder="Your name">', 1, 'placeholder attribute'],
            'an aria-label'               => ['<button aria-label="Close"><i></i></button>', 1, 'aria-label attribute'],
            'a confirm prompt'            => ['<button data-owa-confirm-title="Delete it?"><i></i></button>', 1, 'data-owa-confirm-title attribute'],
            'a submit value'              => ['<input type="submit" value="Save Settings">', 1, 'value attribute'],
            'a button value'              => ['<button value="Go"></button>', 1, 'value attribute'],
            'an onclick confirm()'        => ['<a onclick="return confirm(\'Are you sure?\')"><i></i></a>', 1, 'onclick attribute'],
            'an option label'             => ['<select><option value="a">Ascending</option></select>', 1, 'text'],
            'an echoed literal'           => ["<p>\n<?php echo 'Nothing yet.'; ?></p>", 2, 'output literal (text)'],
            'a short echo'                => ['<p><?= "Nothing yet." ?></p>', 1, 'output literal (text)'],
            'a literal given to out()'    => ['<p><?php $view->out( $n ? $n : \'None\' ); ?></p>', 1, 'output literal (text)'],
            'a sprintf format'            => ['<p><?php $view->out( sprintf( \'%d rows\', $n ) ); ?></p>', 1, 'output literal (text)'],
            'an echoed user attribute'    => ['<i aria-label="<?php echo $x ? \'Open\' : \'\'; ?>"></i>', 1, 'output literal (aria-label attribute)'],
            'markup with English in it'   => ['<p><?php echo \'<b>Note</b>\'; ?></p>', 1, 'output literal (text)'],
            'English inside outMarkup()'  => ['<p><?php $view->outMarkup( \OWA\Core\CoreAPI::t( \'See %s.\' ), \'<a href="x">the guide</a>\' ); ?></p>', 1, 'output literal (text)'],
        ];
    }

    /**
     * What a good template looks like: nothing in it is reported.
     *
     * @dataProvider translatedOrNotLanguage
     */
    public function testTranslatedTextAndWhatIsNotLanguagePass(string $template): void
    {
        $this->assertSame([], UserFacingStrings::inTemplate($template));
    }

    public static function translatedOrNotLanguage(): array
    {
        return [
            'through t()'                 => ['<p><?php $view->out( \OWA\Core\CoreAPI::t( \'Save your changes.\' ) ); ?></p>'],
            'through tn() and sprintf()'  => ['<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::tn( \'%d row\', \'%d rows\', $n ), $n ) ); ?></p>'],
            'an attribute through t()'    => ['<a title="<?php $view->out( \OWA\Core\CoreAPI::t( \'Edit\' ) ); ?>"></a>'],
            'outMarkup() with markup'     => ['<p><?php $view->outMarkup( \OWA\Core\CoreAPI::t( \'Send it in the %s header.\' ), \'<code>Authorization</code>\' ); ?></p>'],
            'script and style'            => ["<script>var x = 'Some text';</script><style>.a:after { content: 'More'; }</style>"],
            'code and pre'                => ['<pre>php cli.php cmd=update</pre><code>owa-config.php</code>'],
            'a comment and a doctype'     => ["<!DOCTYPE html>\n<!-- Some note -->"],
            'entities and numbers'        => ['<span>&nbsp;&mdash; 12 &hellip; 3.5</span>'],
            'the product name'            => ['<span>Open Web Analytics</span><b>OWA</b>'],
            'a URL and a host'            => ['<span>https://example.com/x</span><span>example.com</span>'],
            'identifiers'                 => ['<span>owa_reportAsOf</span><input placeholder="newsletter_signup">'],
            'machine attributes'          => ['<input type="hidden" name="action" value="Save"><div class="Some Class" id="Main"></div>'],
            'class names echoed in a tag' => ['<li class="a<?php echo $on ? \' is-current\' : \'\'; ?>"<?php echo $on ? \' aria-current="page"\' : \'\'; ?>></li>'],
            'a compared literal'          => ['<?php echo $view->get( \'nav\' ) === \'analytics\' ? $a : $b; ?>'],
            'arguments of other calls'    => ['<a href="<?php echo $view->makeLink( array( \'do\' => \'Some Action\' ) ); ?>"></a>'],
            'a sprintf argument'          => ['<?php printf( \'<th class="%s">\', $on ? \'sorted\' : \'\' ); ?>'],
        ];
    }

    public function testAFindingSaysWhereAndHowToFixIt(): void
    {
        $found = UserFacingStrings::inTemplate("<p>\nRevoke</p>");

        $this->assertSame(
            'modules/Base/templates/x.php:2: untranslated text "Revoke": wrap it in \OWA\Core\CoreAPI::t() (tn() for a count), '
            . "e.g. <?php \$view->out( \\OWA\\Core\\CoreAPI::t( '...' ) ); ?>",
            UserFacingStrings::describe('modules/Base/templates/x.php', $found[0]));
    }
}
