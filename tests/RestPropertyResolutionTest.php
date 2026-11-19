<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Which Property a report request is of when it names none.
 *
 * A report screen resumes on the Property this browser last reported on (the
 * `lpr` cookie), else the first the reader may see. The REST API neither reads
 * nor writes the cookie: a REST request naming neither propertyId nor siteId is
 * of the reader's only Property, or refused with a 422.
 *
 * No database: the reader's sites and the user are stubs.
 */
final class RestPropertyResolutionTest extends TestCase
{
    private $priorType;

    private int $status;

    /** @var array{0: array<mixed>, 1: array<mixed>} the state's stores and initial state */
    private array $state;

    protected function setUp(): void
    {
        $this->priorType = \OWA\Core\CoreAPI::getSetting('base', 'request_type');
        $this->status    = http_response_code() ?: 200;

        $state = \OWA\Core\CoreAPI::serviceSingleton()->request->state;
        $this->state = [$state->stores, $state->initial_state];
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', $this->priorType);
        http_response_code($this->status);

        $state = \OWA\Core\CoreAPI::serviceSingleton()->request->state;
        [$state->stores, $state->initial_state] = $this->state;
    }

    /** The cookie as the request container reads it into state. */
    private function lastProperty(string $propertyId): void
    {
        $state = \OWA\Core\CoreAPI::serviceSingleton()->request->state;
        unset($state->stores[\OWA\Module\Base\Controller\ReportingHome::LAST_PROPERTY_COOKIE]);
        $state->setInitialState(\OWA\Module\Base\Controller\ReportingHome::LAST_PROPERTY_COOKIE, [$propertyId]);
    }

    private static function site(string $propertyId): object
    {
        return new class($propertyId) {
            public function __construct(private string $propertyId)
            {
            }

            public function get($name)
            {
                return $name === 'property_id' ? $this->propertyId : null;
            }
        };
    }

    /** A signed-in reader who holds the capability on any Profile asked about. */
    private static function reader(): object
    {
        return new class {
            public function siteIdForProperty($propertyId)
            {
                return 'owatest-site';
            }

            public function isAdmin()
            {
                return false;
            }

            public function isCapable($cap, $siteId = null)
            {
                return true;
            }
        };
    }

    /** A report controller whose reader may see these Properties' Profiles. */
    private function controller(array $propertyIds, array $params = [], string $class = \OWA\Core\ReportController::class): \OWA\Core\ReportController
    {
        $sites = [];

        foreach ($propertyIds as $i => $propertyId) {
            $sites['owatest-' . $i] = self::site($propertyId);
        }

        $c = $class === \OWA\Module\Base\Controller\SitesRest::class
            ? new class($params) extends \OWA\Module\Base\Controller\SitesRest {
                use RestPropertyResolutionProbe;
            }
            : new class($params) extends \OWA\Core\ReportController {
                use RestPropertyResolutionProbe;
            };

        $c->sites = $sites;

        return $c;
    }

    public function testARestRequestNamingNoPropertyIsRefusedWhenTheReaderHasSeveral(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::REST);
        $this->lastProperty('92000001');

        $c = $this->controller(['92000001', '92000002']);

        $this->assertFalse($c->capable(self::reader()), 'the cookie chose a Property for a REST request');

        $c->authenticatedButNotCapableAction();
        $data = $c->data();

        $this->assertSame('base.restApi', $data['view']);
        $this->assertSame(422, http_response_code());
        $this->assertSame('Name the Property with propertyId, or a Profile with siteId.', $data['error_msg']);
    }

    public function testARestRequestNamingNoPropertyIsOfTheReadersOnlyOne(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::REST);

        $c = $this->controller(['92000001', '92000001']);

        $this->assertTrue($c->capable(self::reader()));
        $this->assertSame('92000001', $c->getParam('propertyId'));
    }

    public function testARestRequestNamingAProfileIsNotRefused(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::REST);

        $c = $this->controller(['92000001', '92000002'], ['siteId' => 'owatest-0']);

        $this->assertTrue($c->capable(self::reader()));
    }

    /** v1/sites is not of a Property, so it is not refused for naming none. */
    public function testTheSitesRouteNeedsNoProperty(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::REST);

        $c = $this->controller(['92000001', '92000002'], [], \OWA\Module\Base\Controller\SitesRest::class);

        $this->assertTrue($c->capable(self::reader()));
        $this->assertSame('', $c->resolve());
    }

    public function testTheRestApiNeitherReadsNorWritesTheCookie(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::REST);
        $this->lastProperty('92000002');

        $c = $this->controller(['92000001', '92000002']);

        $this->assertSame('', $c->resolve());
        $this->assertFalse($c->remembers());
    }

    public function testAReportScreenStillReadsAndWritesTheCookie(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::UI);
        $this->lastProperty('92000002');

        $c = $this->controller(['92000001', '92000002']);

        $this->assertSame('92000002', $c->resolve());
        $this->assertTrue($c->remembers());
        $this->assertTrue($c->capable(self::reader()), 'a screen is not refused for naming no Property');
    }

    public function testTheOnlyPropertyOfSomeSites(): void
    {
        $only = [\OWA\Core\ReportController::class, 'onlyPropertyOf'];

        $this->assertSame('7', $only([self::site('7'), self::site('7'), self::site('')]));
        $this->assertSame('', $only([self::site('7'), self::site('8')]));
        $this->assertSame('', $only([self::site('0')]));
        $this->assertSame('', $only([]));
    }
}

/** Opens a report controller's Property resolution to the test above. */
trait RestPropertyResolutionProbe
{
    public array $sites = [];

    protected function getSitesAllowedForCurrentUser()
    {
        return $this->sites;
    }

    public function capable($user): bool
    {
        return (bool) $this->isCurrentUserCapable($user);
    }

    public function resolve(): string
    {
        return (string) $this->resolvePropertyId($this->sites);
    }

    public function remembers(): bool
    {
        return (bool) $this->remembersLastProperty();
    }

    public function data(): array
    {
        return $this->data;
    }
}
