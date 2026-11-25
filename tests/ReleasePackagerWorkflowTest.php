<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Release Packager (.github/workflows/main.yml): what it packages, and
 * what can reach the signing key.
 */
final class ReleasePackagerWorkflowTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function workflow(): array {

        return Yaml::parseFile( dirname( __DIR__ ) . '/.github/workflows/main.yml' );
    }

    /** @return array<string, array<string, mixed>> */
    private static function jobs(): array {

        return self::workflow()['jobs'];
    }

    /** @return list<array<string, mixed>> every step, of every job */
    private static function steps(): array {

        return array_merge( ...array_values( array_map( function ( $job ) {
            return $job['steps'];
        }, self::jobs() ) ) );
    }

    /**
     * It checked out master whatever branch it was dispatched on. Once master
     * holds 2.0, a 1.x release run from the 1.x branch would have packaged 2.0
     * under a 1.x version number. The later jobs check out the commit the first
     * pushed, so what is built and signed is that commit.
     */
    public function testItPackagesTheBranchItRunsOn(): void {

        $jobs = self::jobs();

        $this->assertSame( 'branch', array_key_first( $jobs ) );

        foreach ( $jobs as $name => $job ) {

            $checkouts = array_values( array_filter( $job['steps'], function ( $s ) {
                return strpos( (string) ( $s['uses'] ?? '' ), 'actions/checkout@' ) === 0;
            } ) );

            $this->assertCount( 1, $checkouts, "$name has one checkout" );
            $this->assertSame( $name === 'branch' ? '${{ github.ref }}' : '${{ needs.branch.outputs.sha }}',
                $checkouts[0]['with']['ref'], "$name checks out the wrong ref" );

            if ( $name !== 'branch' ) {
                $this->assertFalse( $checkouts[0]['with']['persist-credentials'], "$name keeps no token in .git" );
            }
        }
    }

    /**
     * npm's and composer's install scripts, and a third-party action, run
     * code that could read the key from the step that has it, or replace the
     * script that step runs.
     */
    public function testOnlyTheSigningJobHasTheKeyAndItRunsNoDependency(): void {

        $holders = [];

        foreach ( self::jobs() as $name => $job ) {

            if ( strpos( Yaml::dump( $job, 10 ), 'secrets.' ) !== false ) {
                $holders[] = $name;
            }
        }

        $this->assertSame( [ 'sign' ], $holders );

        $sign = self::jobs()['sign'];

        $this->assertSame( 'release-signing', $sign['environment'] );

        foreach ( $sign['steps'] as $step ) {

            if ( isset( $step['uses'] ) ) {
                $this->assertMatchesRegularExpression( '~^actions/(checkout|download-artifact)@~', $step['uses'] );
            }

            $this->assertDoesNotMatchRegularExpression( '/\b(npm|npx|composer|node|pip|curl|wget)\b/', (string) ( $step['run'] ?? '' ),
                'the signing job runs a dependency: ' . ( $step['name'] ?? '' ) );
        }
    }

    /** The build job's dependencies can change the tarball; what is signed must be the commit's files (release-verify.sh). */
    public function testTheTarballIsCheckedAgainstTheCommitBeforeItIsSigned(): void {

        $runs = array_map( function ( $s ) {
            return (string) ( $s['run'] ?? '' );
        }, self::jobs()['sign']['steps'] );

        $find = function ( $script ) use ( $runs ) {
            foreach ( $runs as $i => $run ) {
                if ( strpos( $run, $script ) !== false ) {
                    return $i;
                }
            }
            return null;
        };

        $this->assertNotNull( $find( 'release-verify.sh' ) );
        $this->assertLessThan( $find( 'release-sign.php' ), $find( 'release-verify.sh' ) );
    }

    public function testEveryActionIsPinnedToACommit(): void {

        foreach ( self::steps() as $step ) {

            if ( isset( $step['uses'] ) ) {
                $this->assertMatchesRegularExpression( '/@[0-9a-f]{40}$/', $step['uses'] );
            }
        }
    }

    /** An expression in a run: script is pasted into it before the shell parses it. */
    public function testNoExpressionIsPastedIntoAScript(): void {

        foreach ( self::steps() as $step ) {
            $this->assertStringNotContainsString( '${{', (string) ( $step['run'] ?? '' ), (string) ( $step['name'] ?? '' ) );
        }
    }

    public function testEachJobGetsOnlyThePermissionsItDeclares(): void {

        $this->assertSame( [], self::workflow()['permissions'] );

        foreach ( self::jobs() as $name => $job ) {
            $this->assertArrayHasKey( 'permissions', $job, $name );
        }

        $this->assertSame( [ 'contents' => 'read' ], self::jobs()['build']['permissions'] );
    }

    /** The first step refuses what release-sign.php would refuse, before anything uses the version. */
    public function testTheVersionIsCheckedFirst(): void {

        $check = self::jobs()['branch']['steps'][0];

        $this->assertSame( 'Check the version', $check['name'] );

        foreach ( [ '2.0.3' => 0, '10.12.1' => 0, '' => 1, '2.0' => 1, '2.0.3-rc1' => 1, '2.0.3"; touch x; "' => 1, "2.0.3\n1.0.0" => 1 ] as $v => $want ) {

            $p = proc_open( [ 'bash', '-c', $check['run'] ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, sys_get_temp_dir(), [ 'VERSION' => (string) $v ] );
            stream_get_contents( $pipes[1] );
            stream_get_contents( $pipes[2] );

            $this->assertSame( $want, proc_close( $p ), "version '$v'" );
        }
    }
}
