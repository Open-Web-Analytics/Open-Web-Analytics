<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Base registers nothing for install_complete. InstallBase still raises the
 * event, for a module of its own to hear.
 */
final class InstallCompleteHandlerTest extends TestCase
{
    /** @return list<mixed> the listeners install_complete reaches */
    private function listeners(): array
    {
        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();
        $all      = ( new ReflectionProperty( $dispatch, 'listeners' ) )->getValue( $dispatch );

        $out = [];

        foreach ( $dispatch->listenersFor( 'install_complete' ) as $id ) {
            $out[] = $all[ $id ];
        }

        return $out;
    }

    public function testBaseRegistersNoHandlerForInstallComplete(): void
    {
        new \OWA\Module\Base\Module();

        $base = array_filter( $this->listeners(), static function ( $listener ) {

            $target = is_array( $listener ) ? ( $listener[0] ?? null ) : $listener;

            return $target instanceof \OWA\Module\Base\Module;
        } );

        $this->assertSame( [], $base, 'Base still listens for install_complete' );
    }

    public function testBaseHasNoInstallCompleteHandler(): void
    {
        $this->assertFalse( method_exists( \OWA\Module\Base\Module::class, 'installCompleteHandler' ) );
    }
}
