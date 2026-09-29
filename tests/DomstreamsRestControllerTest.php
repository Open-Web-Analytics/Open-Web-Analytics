<?php

require_once(__DIR__ . '/RestControllerTestCase.php');
require_once(__DIR__ . '/DomstreamFixtures.php');

/**
 * GET domstreams: one recording's samples, for the player.
 *
 * Reached through callEndpoint(), which loads the controller from its file, so
 * these run whether or not the Domstream module is active.
 *
 * success() -> 201 (domstream.domstreamsRest); validation failure -> 422.
 */
final class DomstreamsRestControllerTest extends RestControllerTestCase
{
    private const CTRL = \OWA\Module\Domstream\Controller\DomstreamsRestController::class;

    private const RECORDING = '9100000000000000301';

    private array $seeded = [];

    protected function setUp(): void
    {
        parent::setUp();

        DomstreamFixtures::ensure();
    }

    protected function tearDown(): void
    {
        foreach ($this->seeded as $site) {
            DomstreamFixtures::deleteSite($site);
        }

        parent::tearDown();
    }

    private function ctrlFile(): string
    {
        return OWA_MODULES_DIR . 'Domstream/Controller/DomstreamsRestController.php';
    }

    /** Two chunks, stored out of order. */
    private function seedRecording(array $site): void
    {
        $this->seeded[] = $site['site_id'];

        DomstreamFixtures::chunk($site['site_id'], ['recording_id' => self::RECORDING, 'seq' => 2,
            'viewport_w' => 999, 'viewport_h' => 999], [[5, 's', 200], [5, 'k', 'input', 'q', 'q']]);
        DomstreamFixtures::chunk($site['site_id'], ['recording_id' => self::RECORDING, 'seq' => 1,
            'viewport_w' => 1024, 'viewport_h' => 768], [[0, 'm', 10, 10], [7, 'c', 10, 10, 'a', 'buy', '']]);
    }

    private function call(array $params): array
    {
        return $this->callEndpoint(self::CTRL, $this->ctrlFile(), $params);
    }

    public function testRejectsUnauthenticated(): void
    {
        $site = $this->makeSite();

        $this->assertNotAuthenticated(
            $this->call(['siteId' => $site['site_id'], 'recording_id' => self::RECORDING]), 'GET /domstreams');
    }

    public function testSiteAndRecordingAreRequired(): void
    {
        $site = $this->makeSite();
        $this->authenticateAs('admin');

        $this->assertSame(422, $this->call(['recording_id' => self::RECORDING])['status']);
        $this->assertSame(422, $this->call(['siteId' => $site['site_id']])['status']);
    }

    public function testTheRecordingIsItsChunksSamplesInSeqOrder(): void
    {
        $site = $this->makeSite();
        $this->seedRecording($site);
        $this->authenticateAs('admin');

        $resp = $this->call(['siteId' => $site['site_id'], 'recording_id' => self::RECORDING]);

        $this->assertSame(201, $resp['status']);
        $this->assertSame('domstream.domstreamsRest', $resp['view']);
        $this->assertTrue(class_exists(\OWA\Module\Domstream\View\DomstreamsRest::class));

        $recording = \OWA\Module\Domstream\Controller\DomstreamsRestController::recording(
            $site['site_id'], self::RECORDING);

        $this->assertSame([
            [0, 'm', 10, 10], [7, 'c', 10, 10, 'a', 'buy', ''],
            [5, 's', 200], [5, 'k', 'input', 'q', 'q'],
        ], $recording['samples']);
        $this->assertSame(1024, $recording['viewport_w'], 'the first chunk\'s viewport');
        $this->assertSame(768, $recording['viewport_h']);
        $this->assertStringContainsString('"buy"', $resp['raw']);
    }

    public function testARecordingOnAnotherSiteIsNotServed(): void
    {
        $siteA = $this->makeSite('a');
        $siteB = $this->makeSite('b');
        $this->seedRecording($siteA);
        $this->authenticateAs('admin');

        $resp = $this->call(['siteId' => $siteB['site_id'], 'recording_id' => self::RECORDING]);

        $this->assertSame(201, $resp['status']);
        $this->assertStringNotContainsString('"buy"', $resp['raw']);
        $this->assertSame([], \OWA\Module\Domstream\Controller\DomstreamsRestController::recording(
            $siteB['site_id'], self::RECORDING)['samples']);
    }

    public function testARecordingIdThatIsNotAnIdFindsNothing(): void
    {
        $site = $this->makeSite();
        $this->seedRecording($site);

        foreach (["0", "-1", self::RECORDING . ' OR 1=1', 'abc'] as $id) {
            $this->assertSame([], \OWA\Module\Domstream\Controller\DomstreamsRestController::recording(
                $site['site_id'], $id)['samples'], $id);
        }
    }
}
