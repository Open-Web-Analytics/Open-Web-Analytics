<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/** Controller\Cli::readableSize(): KB, or MB above one megabyte. */
final class CliReadableSizeTest extends TestCase
{
    public function testKilobytesThenMegabytes(): void
    {
        // Static, so no command is built: a Cli constructor exits outside cli.php.
        $size = fn ( $bytes ) => ( new \ReflectionMethod( \OWA\Core\Controller\Cli::class, 'readableSize' ) )->invoke( null, $bytes );

        $this->assertSame( '0 KB', $size( 0 ) );
        $this->assertSame( '2 KB', $size( 2048 ) );
        $this->assertSame( '1024 KB', $size( 1048576 ) );
        $this->assertSame( '1.5 MB', $size( 1572864 ) );
    }
}
