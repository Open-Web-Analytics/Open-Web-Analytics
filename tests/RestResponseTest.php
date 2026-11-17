<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Controller::restResponse(): the base.restApi view and the status code, with
 * the response's data read from the key the controller named.
 */
final class RestResponseTest extends TestCase
{
    private int $status;

    protected function setUp(): void
    {
        $this->status = http_response_code() ?: 200;
    }

    protected function tearDown(): void
    {
        http_response_code($this->status);
    }

    private function controller(): \OWA\Core\Controller
    {
        return new class(array()) extends \OWA\Core\Controller {
            public function answer($status, $key = '') {
                $this->restResponse($status, $key);
                return $this->data;
            }
        };
    }

    /** The envelope's data member as the view renders it. */
    private function renderedData(array $data)
    {
        ob_start();
        $returned = \OWA\Core\CoreAPI::displayView($data);
        $captured = ob_get_clean();
        $json = json_decode(($returned !== null && $returned !== '') ? (string) $returned : (string) $captured, true);

        $this->assertIsArray($json);
        $this->assertArrayHasKey('data', $json);

        return $json['data'];
    }

    public function testTheViewAndStatusCode(): void
    {
        $data = $this->controller()->answer(201, 'site');

        $this->assertSame('base.restApi', $data['view']);
        $this->assertSame('site', $data['response_key']);
        $this->assertSame(201, http_response_code());
    }

    public function testTheDataIsTheNamedKeysValue(): void
    {
        $data = $this->controller()->answer(201, 'site');
        $data['site'] = ['site_id' => 'abc'];

        $this->assertSame(['site_id' => 'abc'], $this->renderedData($data));
    }

    public function testANamedKeyNeverSetIsFalse(): void
    {
        $this->assertFalse($this->renderedData($this->controller()->answer(422, 'site')));
    }

    public function testNoKeyIsNoData(): void
    {
        $data = $this->controller()->answer(202);

        $this->assertArrayNotHasKey('response_key', $data);
        $this->assertNull($this->renderedData($data));
    }
}
