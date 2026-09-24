<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Metrics and dimensions are declared in a file, and core reads it.
 *
 * A dimension is a name, a column and what to call it; a metric definition
 * names a column and a kind the query builder already knows how to render.
 * Neither carries code, so both belong beside the other vocabularies core reads
 * from a file -- `reports/*.json`, `config/tracking_properties.json`,
 * `<module>/settings.php`.
 *
 * What is pinned here is the MECHANISM: any module shipping the file gets its
 * vocabulary registered with no code, and a file that cannot say what it is
 * about is refused rather than half-applied.
 */
final class VocabularyConfigTest extends TestCase
{
    /** @var string */
    private $dir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-vocab-' . bin2hex(random_bytes(4)) . '/';

        mkdir($this->dir . 'config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['dimensions', 'metrics'] as $kind) {
            @unlink($this->dir . 'config/' . $kind . '.php');
        }

        @rmdir($this->dir . 'config');
        @rmdir($this->dir);
    }

    /** A module object whose path is the scratch directory. */
    private function module()
    {
        $module = (new ReflectionClass(\OWA\Module\Hello\Module::class))
            ->newInstanceWithoutConstructor();

        $p = new ReflectionProperty(\OWA\Core\Module::class, 'path');
        $p->setAccessible(true);
        $p->setValue($module, $this->dir);

        return $module;
    }

    private function write(string $kind, string $php): void
    {
        file_put_contents($this->dir . 'config/' . $kind . '.php', "<?php\nreturn " . $php . ";\n");
    }

    private function read($module, string $kind)
    {
        $m = new ReflectionMethod(\OWA\Core\Module::class, 'readVocabulary');
        $m->setAccessible(true);

        return $m->invoke($module, $kind);
    }

    /** Base ships one, and it is what registered the cube's dimensions. */
    public function testBaseShipsItsVocabularyAsFiles(): void
    {
        foreach (['dimensions', 'metrics'] as $kind) {

            $file = OWA_DIR . 'modules/Base/config/' . $kind . '.php';

            $this->assertFileExists($file,
                $kind . ' belong in a file, not in a class literal');

            $declaration = include $file;

            $this->assertSame('base.event', $declaration['entity']);
            $this->assertNotEmpty($declaration[$kind]);
        }
    }

    /** The declarations reached the registry, which is the loader's whole job. */
    public function testWhatTheFileDeclaresIsRegistered(): void
    {
        $declared = (array) (include OWA_DIR . 'modules/Base/config/dimensions.php')['dimensions'];

        $service = owa_coreAPI::serviceSingleton();

        $r = new ReflectionObject($service);
        $p = $r->getProperty('denormalizedDimensions');
        $p->setAccessible(true);

        $registered = (array) $p->getValue($service);

        $missing = [];

        foreach (array_keys($declared) as $name) {

            if (!isset($registered[$name]['base.event'])) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing,
            'these are declared in the file and never reached the registry');

        $p = $r->getProperty('metrics');
        $p->setAccessible(true);

        $this->assertArrayHasKey('eventCount', (array) $p->getValue($service));
    }

    /**
     * The FILE is the only place the cube's vocabulary is declared.
     *
     * v1's registrations are still inline in Module.php, but they are residue
     * being deleted as each is replaced -- NOT a path being maintained. Nothing
     * uses v1 reporting on this branch; that is what the branch is for. So this
     * asserts one direction only: a cube dimension or metric added the old way
     * fails here. It says nothing about what else Module.php still contains,
     * because that number should only ever go down.
     */
    public function testTheCubesVocabularyIsDeclaredOnlyInTheFile(): void
    {
        $this->assertStringNotContainsString(
            "'base.event'",
            (string) file_get_contents(OWA_DIR . 'modules/Base/Module.php'),
            'the cube\'s vocabulary belongs in config/dimensions.php and '
          . 'config/metrics.php, not in a registration call');
    }

    /** No file is not an error -- most modules have no vocabulary. */
    public function testAModuleWithNoFileIsFine(): void
    {
        $this->assertNull($this->read($this->module(), 'dimensions'));
    }

    /**
     * A file that cannot say which entity its names read from is REFUSED.
     *
     * Half-applying it would register a vocabulary against nothing, and the
     * failure would surface as an empty report rather than as a bad file.
     */
    public function testAFileWithNoEntityIsRefusedRatherThanHalfApplied(): void
    {
        $this->write('dimensions', "array( 'dimensions' => array(
            'x' => array( 'column' => 'c', 'label' => 'X', 'family' => 'f' ) ) )");

        $this->assertNull($this->read($this->module(), 'dimensions'));
    }

    /** As is one that declares an entity and no vocabulary. */
    public function testAFileWithNoVocabularyIsRefused(): void
    {
        $this->write('dimensions', "array( 'entity' => 'base.event' )");

        $this->assertNull($this->read($this->module(), 'dimensions'));
    }

    /** A well-formed one comes back whole. */
    public function testAWellFormedFileIsAccepted(): void
    {
        $this->write('dimensions', "array( 'entity' => 'base.event', 'dimensions' => array(
            'x' => array( 'column' => 'c', 'label' => 'X', 'family' => 'f' ) ) )");

        $declaration = $this->read($this->module(), 'dimensions');

        $this->assertSame('base.event', $declaration['entity']);
        $this->assertArrayHasKey('x', $declaration['dimensions']);
    }

    /**
     * Denormalised is the default, because a vocabulary declared this way reads
     * columns off one wide table. A normalised dimension needs a foreign key to
     * name, so it has to say so.
     */
    public function testDimensionsAreDenormalisedUnlessTheFileSaysOtherwise(): void
    {
        $module = $this->module();

        $this->write('dimensions', "array( 'entity' => 'base.event', 'dimensions' => array(
            'zzDefault'  => array( 'column' => 'c', 'label' => 'D', 'family' => 'f' ),
            'zzExplicit' => array( 'column' => 'c', 'label' => 'E', 'family' => 'f',
                                   'denormalized' => false, 'foreign_key_name' => 'fk' ) ) )");

        $m = new ReflectionMethod(\OWA\Core\Module::class, 'registerDimensionsFromConfig');
        $m->setAccessible(true);
        $m->invoke($module);

        $this->assertArrayHasKey('base.event', $module->denormalizedDimensions['zzDefault'],
            'a row saying nothing must land denormalised');

        $this->assertArrayHasKey('base.event', $module->dimensions['zzExplicit'],
            'and one saying otherwise must land normalised');
    }
}
