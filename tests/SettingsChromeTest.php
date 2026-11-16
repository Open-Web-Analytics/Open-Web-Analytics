<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * AdminController::settingsChrome(): the params merged, the site hierarchy,
 * the settings nav for the Profile and Property given, and the
 * base.optionsHierarchy view.
 */
final class SettingsChromeTest extends TestCase
{
    private function controller(array $params): \OWA\Core\AdminController
    {
        return new class($params) extends \OWA\Core\AdminController {
            public function chrome(array $params, $siteId = '', $propertyId = '') {
                $this->settingsChrome($params, $siteId, $propertyId);
                return $this->data;
            }
            public function expected($siteId, $propertyId) {
                return array(
                    'site_hierarchy' => $this->getSiteHierarchy($this->getSitesAllowedForCurrentUser()),
                    'hierarchy_nav'  => $this->getHierarchyNav($siteId, $propertyId),
                );
            }
        };
    }

    public function testTheChromeAndTheView(): void
    {
        $c = $this->controller(['do' => 'base.optionsGeneral']);
        $data = $c->chrome(['propertyId' => '42'], '', '42');

        $this->assertSame('base.optionsHierarchy', $data['view']);
        $this->assertSame('base.optionsGeneral', $data['params']['do']);
        $this->assertSame('42', $data['params']['propertyId']);

        $expected = $c->expected('', '42');
        $this->assertEquals($expected['site_hierarchy'], $data['site_hierarchy']);
        $this->assertEquals($expected['hierarchy_nav'], $data['hierarchy_nav']);
    }

    public function testNoParamsLeavesTheParamsAlone(): void
    {
        $c = $this->controller(['do' => 'base.profileSettings']);
        $before = $c->data['params'] ?? null;

        $data = $c->chrome([]);

        $this->assertSame($before, $data['params'] ?? null);
        $this->assertSame('base.optionsHierarchy', $data['view']);
    }
}
