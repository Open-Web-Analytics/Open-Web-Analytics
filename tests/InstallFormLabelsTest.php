<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every field on the installer's two forms has a label tied to it: either the
 * label wraps the field or its `for` names the field's id.
 */
class InstallFormLabelsTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function forms(): array
    {
        return [
            'config'   => [ 'install_config_entry.php', [
                'config'     => [ 'db_mode' => 'existing', 'db_create_name' => 'owa_org_1', 'db_name' => '' ],
                'public_url' => 'https://example.test/',
            ] ],
            'defaults' => [ 'install_defaults_entry.php', [ 'defaults' => [] ] ],
        ];
    }

    /**
     * @dataProvider forms
     * @param array<string, mixed> $vars
     */
    public function testEveryFieldHasALabel( string $template, array $vars ): void
    {
        $t = new \OWA\Core\Template( 'base' );

        foreach ( $vars as $k => $v ) {
            $t->set( $k, $v );
        }

        $this->assertTrue( $t->set_template( $template ) );

        $doc = new DOMDocument();
        @$doc->loadHTML( '<?xml encoding="utf-8"?>' . (string) $t->fetch() );
        $xp = new DOMXPath( $doc );

        $fields = $xp->query( '//input[not(@type="hidden") and not(@type="submit")] | //select' );
        $this->assertNotFalse( $fields );
        $this->assertGreaterThan( 3, $fields->length );

        $ids = [];

        foreach ( $fields as $f ) {
            /** @var DOMElement $f */
            $name = $f->getAttribute( 'name' );

            if ( $xp->query( 'ancestor::label', $f )->length > 0 ) {
                continue;
            }

            $id = $f->getAttribute( 'id' );
            $this->assertNotSame( '', $id, $name . ' has an id' );
            $this->assertSame( 1, $xp->query( '//label[@for="' . $id . '"]' )->length, $name . ' has a label for it' );
            $ids[] = $id;
        }

        $this->assertSame( array_unique( $ids ), $ids, 'ids are unique' );

        // No label is left pointing at nothing, or at no field.
        foreach ( $xp->query( '//label[not(.//input) and not(.//select)]' ) as $label ) {
            /** @var DOMElement $label */
            $this->assertContains( $label->getAttribute( 'for' ), $ids, trim( $label->textContent ) . ' names its field' );
        }
    }
}
