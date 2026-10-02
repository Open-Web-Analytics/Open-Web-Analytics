<?php

use PHPUnit\Framework\TestCase;

/**
 * The SQS settings page renders, and says what an administrator needs: the
 * derived queue names, whether provisioning worked, and where credentials
 * come from (PLAN 2.30.4a).
 *
 * Asserted on the render, as MaxmindOptionsPageTest explains: a template that
 * fails renders as nothing.
 */
final class SqsOptionsPageTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function render( array $overrides = array() ): string {

        $template = new \OWA\Core\Template( 'sqs' );
        $template->setTemplateFile( 'sqs', 'options_sqs.php' );

        $data = array_merge( array(
            'settings_fieldsets'  => owa_test_page_fieldsets( 'sqs.optionsSqs', \OWA\Module\Sqs\Module::class ),
            'queue_name'          => 'owa-tracker-ingest-abc123def456',
            'dlq_name'            => 'owa-tracker-ingest-abc123def456-dlq',
            'credential_source'   => \OWA\Module\Sqs\Classes\Sqs::credentialSource(),
            'region'              => 'us-east-1',
            'provisioned'         => null,
            'in_use'              => false,
            'settings_page_title' => 'AWS SQS',
        ), $overrides );

        foreach ( $data as $key => $value ) {
            $template->set( $key, $value );
        }

        return (string) $template->fetch();
    }

    public function testThePageRendersWithTheRegionField(): void {

        $html = $this->render();

        $this->assertStringContainsString( 'class="panel_headline">AWS SQS<', $html );
        $this->assertStringContainsString( 'config[sqs.region]', $html );
        $this->assertStringContainsString( 'value="sqs.optionsSqsUpdate"', $html );
    }

    public function testItNamesTheQueuesAndHasNoFieldForThem(): void {

        $html = $this->render();

        $this->assertStringContainsString( 'owa-tracker-ingest-abc123def456-dlq', $html );
        $this->assertStringNotContainsString( 'config[sqs.queue', $html );
    }

    public function testItSaysWhetherProvisioningWorked(): void {

        $this->assertStringContainsString( 'cmd=tracker-ingest-provision', $this->render() );

        $this->assertStringContainsString( 'In place since',
            $this->render( array( 'provisioned' => array( 'ok' => true, 'at' => 1700000000, 'error' => null ) ) ) );

        $failed = $this->render( array( 'provisioned' => array(
            'ok' => false, 'at' => 1700000000, 'error' => 'AccessDenied on sqs:CreateQueue <b>' ) ) );

        $this->assertStringContainsString( 'AccessDenied on sqs:CreateQueue', $failed );
        $this->assertStringNotContainsString( '<b>', $failed, 'an AWS error is escaped' );
    }

    public function testItNamesWhereCredentialsComeFromAndTheActionsNeeded(): void {

        $html = $this->render();

        $this->assertStringContainsString( 'default chain', $html );
        $this->assertStringContainsString( 'never stored', $html );
        $this->assertStringContainsString( 'sqs:ChangeMessageVisibility', $html );
    }

    public function testItSaysHowToPutTheIntakeOnSqs(): void {

        $this->assertStringContainsString( "OWA_TRACKER_INGEST_QUEUE_TYPE', 'sqs'", $this->render() );
        $this->assertStringContainsString( 'Yes:', $this->render( array( 'in_use' => true ) ) );
    }
}
