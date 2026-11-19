<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TemplateRawOutput.php';

/**
 * No template prints a value unescaped, unless the line says why it is
 * trusted. TemplateRawOutput says what counts.
 */
final class TemplateOutputGuardTest extends TestCase
{
    /** The checkout, without booting OWA: this test reads files only. */
    private static function root(): string
    {
        return dirname(__DIR__) . '/';
    }

    /** @dataProvider templates */
    public function testTheTemplatePrintsNothingUnescaped(string $file): void
    {
        $found = array_map(function ($f) use ($file) {
            return sprintf('%s:%d: %s', $file, $f['line'], $f['what']);
        }, TemplateRawOutput::inTemplate((string) file_get_contents(self::root() . $file)));

        $this->assertSame([], $found, "unescaped output in $file (escape it with \$view->out(), or say why it is"
            . " trusted in a comment on the line: /* " . TemplateRawOutput::MARKER . " ... */):\n" . implode("\n", $found));
    }

    public static function templates(): array
    {
        $out = [];

        foreach (glob(self::root() . 'modules/*/templates/*.php') as $path) {
            $file = substr($path, strlen(self::root()));
            $out[$file] = [$file];
        }

        return $out;
    }

    /**
     * What a raw output looks like, each caught with the line it is on.
     *
     * @dataProvider raw
     */
    public function testARawOutputIsFound(string $template, int $line, string $what): void
    {
        $found = TemplateRawOutput::inTemplate($template);

        $this->assertCount(1, $found, print_r($found, true));
        $this->assertSame($line, $found[0]['line']);
        $this->assertSame($what, $found[0]['what']);
    }

    public static function raw(): array
    {
        return [
            'a property'                => ['<title>x - <?php echo $view->page_title ?? \'\';?></title>', 1, '$view->page_title'],
            'a short echo'              => ["<p>\n<?= \$name ?></p>", 2, '$name'],
            'print'                     => ['<?php print $v[\'name\']; ?>', 1, '$v[\'name\']'],
            'a ternary branch'          => ['<?php echo $on ? $label : \'\'; ?>', 1, '$label'],
            'a short ternary'           => ['<?php echo $label ?: \'none\'; ?>', 1, '$label'],
            'a concatenation'           => ['<?php echo \'<b>\' . $label . \'</b>\'; ?>', 1, '$label'],
            'an interpolation'          => ['<?php echo "<b>$label</b>"; ?>', 1, '$label'],
            'a printf argument'         => ["<?php printf(\n'<a href=\"%s\">%s</a>',\n\$url, \$view->escape( \$t ) ); ?>", 1, '$url'],
            'a sprintf argument in an echo' => ['<?php echo sprintf( \'<b>%s</b>\', $label ); ?>', 1, '$label'],
            'a chained call'            => ['<?php echo \OWA\Core\CoreAPI::configSingleton()->assetsUrl(); ?>', 1, '\OWA\Core\CoreAPI::configSingleton()->assetsUrl()'],
            'an unescaping out()'       => ['<a href="<?php $view->out( $url, false ); ?>"></a>', 1, '$url'],
            'a method that escapes nothing' => ['<?php echo $view->get( \'x\' ); ?>', 1, '$view->get(\'x\')'],
            'a static call'             => ['<?php echo \OWA\Core\CoreAPI::getSetting( \'base\', \'ns\' ); ?>', 1, '\OWA\Core\CoreAPI::getSetting(\'base\',\'ns\')'],
            'a translation as markup'   => ['<?php echo \OWA\Core\CoreAPI::t( \'Saved\' ); ?>', 1, '\OWA\Core\CoreAPI::t(\'Saved\')'],
            'a closure'                 => ['<?php echo $link( $x ); ?>', 1, '$link($x)'],
            'a marker on another line'  => ["<?php /* trusted: no */ ?>\n<?php echo \$x; ?>", 2, '$x'],
        ];
    }

    /**
     * What an escaped or trusted output looks like: nothing is reported.
     *
     * @dataProvider safe
     */
    public function testEscapedAndTrustedOutputPasses(string $template): void
    {
        $this->assertSame([], TemplateRawOutput::inTemplate($template));
    }

    public static function safe(): array
    {
        return [
            'out()'                     => ['<?php $view->out( $view->page_title ); ?>'],
            'a literal'                 => ['<?php echo \'SELECTED\'; ?>'],
            'a class constant'          => ['<?php echo \OWA\Core\View::BANNER_SLOT; ?>'],
            'a ternary condition'       => ['<?php echo $owa_tab[\'current\'] ? \'aria-current="page"\' : \'\'; ?>'],
            'a compared condition'      => ['<?php echo $a === $b ? \'on\' : \'\'; ?>'],
            'a nested condition'        => ['<?php echo $on ? \' aria-sort="\' . ( $desc ? \'descending\' : \'ascending\' ) . \'"\' : \'\'; ?>'],
            'a link'                    => ['<a href="<?php echo $view->makeLink( [ \'do\' => $do ] ); ?>"></a>'],
            'script JSON'               => ['<script>var x = <?php echo \OWA\Core\Template::scriptJson( $x ); ?>;</script>'],
            'a number cast'             => ['<?php echo (int) $row[\'count\']; ?>'],
            'number_format()'           => ['<?php echo number_format( $owa_day[\'raw\'] ); ?>'],
            'escaped sprintf arguments' => ['<?php echo sprintf( \'<option value="%s">%s</option>\', $view->escape( $v ), $view->escape( $d ) ); ?>'],
            'out() that escapes'        => ['<?php $view->out( $x, true ); ?>'],
            'a marked output'           => ['<?php echo $view->body; /* trusted: a rendered template */ ?>'],
            'a marked multi-line printf' => ["<?php printf( '%s',\n  \$html ); // trusted: built escaped above\n?>"],
        ];
    }
}
