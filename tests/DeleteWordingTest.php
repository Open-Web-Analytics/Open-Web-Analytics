<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What the delete screens say matches what deleting does.
 *
 * Deleting a Profile or a Property archives it: nothing is destroyed, and no
 * screen or command restores it. An archived Profile's events stay in its
 * Property's cube (Cubes::siteIds() reads every Profile of the Property), and
 * a deleted goal event definition leaves the events it marked marked
 * (goalEvents counts is_goal_event). The texts said the data could be
 * restored and that it left reports.
 */
final class DeleteWordingTest extends TestCase
{
    private const FILES = [
        'conf/messages.php',
        'modules/Base/templates/sites_addoredit.php',
        'modules/Base/templates/property_profile.php',
        'modules/Base/templates/goal_event_definition_edit.php',
    ];

    private function source( string $file ): string
    {
        return (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
    }

    public function testNothingPromisesARestore(): void
    {
        foreach ( self::FILES as $file ) {

            $this->assertDoesNotMatchRegularExpression( '/can (be )?restore/i', $this->source( $file ), $file );
        }
    }

    public function testAProfileAndAGoalDoNotSayTheyLeaveReports(): void
    {
        foreach ( [ 'modules/Base/templates/sites_addoredit.php', 'modules/Base/templates/goal_event_definition_edit.php' ] as $file ) {

            $this->assertDoesNotMatchRegularExpression( '/removed from reports|leaves reporting|leaves reports/i',
                $this->source( $file ), $file );
        }

        $this->assertStringContainsString( "What it has collected stays in its Property\\'s reports.",
            $this->source( 'modules/Base/templates/sites_addoredit.php' ) );
    }

    /** The behaviour the wording describes: a Property's cube reads its archived Profiles too. */
    public function testAPropertyCubeReadsEveryProfileArchivedOrNot(): void
    {
        $src = $this->source( 'modules/Base/Classes/Cube/Cubes.php' );

        $this->assertStringContainsString( "'SELECT site_id FROM %s WHERE property_id = %s'", $src );
    }
}
