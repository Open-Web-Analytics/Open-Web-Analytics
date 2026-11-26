<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Entity\Site;

require_once __DIR__ . '/bootstrap_owa.php';

/** A Profile row's stream type, as the CORS list, the bundles and the Profile screens read it. */
final class SiteStreamTypeOfTest extends TestCase
{
    public function testAnEmptyTypeOrNoRowIsAWebsite(): void
    {
        foreach ([null, [], ['stream_type' => null], ['stream_type' => '']] as $row) {
            $this->assertSame(Site::STREAM_WEB, Site::streamTypeOf($row), var_export($row, true));
        }
    }

    public function testAStoredTypeIsKept(): void
    {
        $this->assertSame(Site::STREAM_APP, Site::streamTypeOf(['stream_type' => 'app']));
        $this->assertSame(Site::STREAM_WEB, Site::streamTypeOf(['stream_type' => 'web']));
    }
}
