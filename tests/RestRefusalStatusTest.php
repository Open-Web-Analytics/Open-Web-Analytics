<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What a REST request answers when it is refused or reads: the base.restApi
 * view and a status a client can act on.
 *
 * No database: the controllers are built and their refusal or success
 * handlers called directly.
 */
final class RestRefusalStatusTest extends TestCase
{
    private $priorType;

    private int $status;

    protected function setUp(): void
    {
        $this->priorType = \OWA\Core\CoreAPI::getSetting('base', 'request_type');
        $this->status    = http_response_code() ?: 200;
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::REST);
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', $this->priorType);
        http_response_code($this->status);
    }

    private function controller(): \OWA\Core\Controller
    {
        return new class([]) extends \OWA\Core\Controller {
            public function data(): array
            {
                return $this->data;
            }
        };
    }

    /** The envelope as the view renders it. */
    private function rendered(array $data): array
    {
        ob_start();
        $returned = \OWA\Core\CoreAPI::displayView($data);
        $captured = ob_get_clean();
        $json = json_decode(($returned !== null && $returned !== '') ? (string) $returned : (string) $captured, true);

        $this->assertIsArray($json, 'the answer is not JSON');

        return $json;
    }

    public function testASignedInCallerWithoutTheCapabilityGetsA403(): void
    {
        $c = $this->controller();
        $c->authenticatedButNotCapableAction('Profile: owatest-probe');
        $data = $c->data();

        $this->assertSame('base.restApi', $data['view']);
        $this->assertSame(403, http_response_code());

        $json = $this->rendered($data);

        $this->assertSame(403, $json['httpResponse']['status_code']);
        $this->assertSame('Profile: owatest-probe', $json['error'][0]['detail']);
    }

    public function testAPendingUpdateAnswers503(): void
    {
        $c = $this->controller();
        $data = $c->updateAction();

        $this->assertSame('base.restApi', $data['view']);
        $this->assertSame(503, http_response_code());
        $this->assertSame(503, $this->rendered($data)['httpResponse']['status_code']);
    }

    public function testASiteThatWasNotCreatedAnswers422(): void
    {
        $c = new class([]) extends \OWA\Module\Base\Controller\AddSiteRest {
            public function answer(array $site): array
            {
                $this->set('site', $site);
                $this->success();

                return $this->data;
            }
        };

        $data = $c->answer([]);

        $this->assertSame(422, http_response_code());
        $this->assertNotEmpty($this->rendered($data)['error']);

        $c->answer(['site_id' => 'owatest-site']);

        $this->assertSame(201, http_response_code());
    }

    public function testAReportReadAnswers200(): void
    {
        $c = new class([]) extends \OWA\Module\Base\Controller\ReportsRest {
            public function answer($response): void
            {
                $this->set('response', $response);
                $this->success();
            }
        };

        $c->answer(new \OWA\Module\Base\Classes\PaginatedResultSet());

        $this->assertSame(200, http_response_code());
    }

    public function testARecordingReadAnswers200(): void
    {
        $c = new \OWA\Module\Domstream\Controller\DomstreamsRestController([]);
        $c->success();

        $this->assertSame(200, http_response_code());
    }

    public function testAReportNameIsRefusedInPlainWords(): void
    {
        $c = new \OWA\Module\Base\Controller\ReportsRest(['report_name' => 'clickstream']);
        $c->validate();
        $c->v->doValidations();

        $this->assertTrue($c->v->hasErrors);
        $this->assertSame(
            ['report_name is not supported. Ask for metrics and dimensions instead.'],
            array_values((array) $c->getValidationErrorMsgs()));
    }

    /**
     * A request whose server variables were never read answers false for any of
     * them. The REST view asks for HTTP_ORIGIN, and a null array threw there in
     * a full suite run.
     */
    public function testARequestWithoutServerVariablesAnswersFalse(): void
    {
        $r = new \OWA\Core\RequestContainer();
        $r->server = null;

        $this->assertFalse($r->getServerParam('HTTP_ORIGIN'));
    }
}
