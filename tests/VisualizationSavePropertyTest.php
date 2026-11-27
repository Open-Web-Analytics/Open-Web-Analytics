<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

/**
 * VisualizationSave resolves the Property before it validates: an edit posted
 * with its siteId and no propertyId (a stale form, a direct post) is checked
 * against that Property, not refused for naming none. The resolution is
 * validate()'s first step.
 */
final class VisualizationSavePropertyTest extends RestControllerTestCase
{
    public function testAnEditNamingItsSiteIsCheckedAgainstItsProperty(): void
    {
        $site = $this->makeSite('vsave');

        $save = new \OWA\Module\Base\Controller\VisualizationSave([
            'visualizationId' => 'any-existing-id', 'siteId' => $site['site_id'], 'name' => 'Funnel',
            'stepEvent' => ['page_view'], 'stepMatch' => ['all'], 'stepName' => [''], 'stepCustomEvent' => [''],
            'stepConditionProperty' => [['page_path']], 'stepConditionOperator' => [['exact']], 'stepConditionValue' => [['/a']],
        ]);

        $save->validate();

        $this->assertSame(\OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite($site['site_id']), (string) $save->getParam('propertyId'));

        $v = (new \ReflectionProperty(\OWA\Core\Controller::class, 'v'))->getValue($save);
        $v->doValidations();

        $this->assertArrayNotHasKey('propertyId', (array) $v->getErrorMsgs());
    }
}
