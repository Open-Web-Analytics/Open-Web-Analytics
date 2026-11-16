<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** Controller\Cli::readableSize(): KB, or MB above one megabyte. */
final class CliReadableSizeTest extends TestCase
{
    public function testKilobytesThenMegabytes(): void
    {
        $cli = new class( array() ) extends \OWA\Core\Controller\Cli {
            public function size( $bytes ) {
                return $this->readableSize( $bytes );
            }
        };

        $this->assertSame( '0 KB', $cli->size( 0 ) );
        $this->assertSame( '2 KB', $cli->size( 2048 ) );
        $this->assertSame( '1024 KB', $cli->size( 1048576 ) );
        $this->assertSame( '1.5 MB', $cli->size( 1572864 ) );
    }
}
