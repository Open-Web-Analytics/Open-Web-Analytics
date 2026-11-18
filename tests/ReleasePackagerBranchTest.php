<?php

use PHPUnit\Framework\TestCase;

/**
 * The Release Packager packages the branch it is run on.
 *
 * It checked out master whatever branch it was dispatched on. Once master
 * holds 2.0, a 1.x release run from the 1.x branch would have packaged 2.0
 * under a 1.x version number.
 */
final class ReleasePackagerBranchTest extends TestCase {

    public function testTheCheckoutIsTheBranchTheWorkflowRunsOn(): void {

        $yml = (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/main.yml' );

        $this->assertSame( 1, preg_match( '/uses: actions\/checkout@\S+\s+with:\s+ref: (\S.*)/', $yml, $m ),
            'the packager has one checkout step with a ref' );
        $this->assertSame( '${{ github.ref }}', trim( $m[1] ) );
    }
}
