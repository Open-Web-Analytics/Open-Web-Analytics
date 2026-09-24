<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Columns;
use OWA\Module\Base\Classes\Cube\NewVsReturningStep;

/**
 * One dimension over a stored label, replacing v1's two booleans.
 *
 * WHY THE LABEL IS STORED, asserted here as the reason rather than assumed:
 * ResultSetManager::applyDimensions() emits `groupBy($dim['column'])` and
 * registerDimension() takes no value labels, so a stored code has no route to
 * two named buckets -- only to a data_type formatter, globally, and the one
 * that fits a two-state column answers Yes and No. That is how v1's pie came to
 * draw two slices both labelled New: three GROUP BY buckets from a nullable
 * tinyint, folded back onto two names by a map in dashboard.json.
 *
 * Nothing here needs a database. The values a build actually writes are
 * asserted against a real cube in CubeBuildTest.
 */
final class NewVsReturningTest extends TestCase
{
    /** The dimension vocabulary as the FILE declares it. */
    private function declared(): array
    {
        $declaration = include OWA_DIR . 'modules/Base/config/dimensions.php';

        return (array) $declaration['dimensions'];
    }

    public function testTheDimensionReadsTheCubeColumn(): void
    {
        $declared = $this->declared();

        $this->assertArrayHasKey('newVsReturning', $declared);
        $this->assertSame('new_vs_returning', $declared['newVsReturning']['column']);
        $this->assertSame('visitor', $declared['newVsReturning']['family']);
    }

    /**
     * It is a plain column read, like every other line of that file.
     *
     * No data_type, so no formatter stands between the stored value and the
     * bucket a report groups into -- which is the whole property being bought.
     */
    public function testItDeclaresNoFormatterToStandBetweenTheValueAndTheBucket(): void
    {
        $this->assertArrayNotHasKey('data_type', $this->declared()['newVsReturning']);
    }

    /**
     * v1's two booleans are gone, not kept alongside it.
     *
     * Asserted over the whole shipped vocabulary rather than by grepping one
     * file: a registration that moved somewhere else is still a registration,
     * and two dimensions partitioning one population is the defect.
     */
    public function testTheV1BooleanTwinsAreNotRegisteredAnywhere(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        // BOTH registries. registerDimension() files a denormalized dimension
        // in a different array from a normalized one, so checking only the one
        // a name used to be in is how a surviving registration hides.
        foreach (['isNewVisitor', 'isRepeatVisitor'] as $name) {

            $this->assertArrayNotHasKey($name, $service->dimensions,
                $name . ' is still registered as a normalized dimension');

            $this->assertArrayNotHasKey($name, $service->denormalizedDimensions,
                $name . ' is replaced by newVsReturning. Keeping it registered keeps the '
              . 'three-bucket nullable boolean that drew a pie with two slices called New.');
        }

        // The replacement IS there, so the assertions above cannot pass by the
        // registry being empty.
        $this->assertArrayHasKey('newVsReturning', $service->denormalizedDimensions);
    }

    /** And no shipped report asks for one. */
    public function testNoShippedReportNamesThem(): void
    {
        $files = glob(OWA_DIR . 'modules/*/reports/*.json');

        $this->assertNotEmpty($files, 'no report configs found; this assertion would be vacuous');

        foreach ($files as $file) {

            $json = file_get_contents($file);

            foreach (['isNewVisitor', 'isRepeatVisitor'] as $needle) {

                $this->assertStringNotContainsString($needle, $json,
                    basename($file) . ' still names ' . $needle);
            }
        }
    }

    /**
     * The dashboard pie groups by the label and needs no folding map.
     *
     * The map it used to carry -- {"1":"Repeat","0":"New","":"New"} -- is the
     * defect written down: three buckets named twice. Asserted on THIS widget
     * rather than on every report, because valueLabels is a legitimate option
     * of the pie and a later report may have a real use for it; what must not
     * come back is this widget needing one.
     */
    public function testTheDashboardPieGroupsByTheLabelWithNoFoldingMap(): void
    {
        $dashboard = json_decode(
            (string) file_get_contents(OWA_DIR . 'modules/Base/reports/dashboard.json'), true);

        $widget = null;

        foreach ($dashboard['widgets'] as $w) {

            if (isset($w['id']) && $w['id'] === 'visitorTypes') { $widget = $w; }
        }

        $this->assertNotNull($widget, 'the visitorTypes widget is gone, so this asserts nothing');

        $this->assertSame('newVsReturning', $widget['query']['dimensions']);
        $this->assertArrayNotHasKey('valueLabels', $widget);
    }

    /** The cube config fills the column, and with this kind. */
    public function testTheCubeConfigFillsTheColumnWithThisStep(): void
    {
        $event = \OWA\Core\CoreAPI::entityFactory('base.event')->getColumns();
        $raw   = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns();

        $steps = (new Columns())->steps(array_slice($event, count($raw)));

        $this->assertArrayHasKey('new_vs_returning', $steps);
        $this->assertInstanceOf(NewVsReturningStep::class, $steps['new_vs_returning']);
    }

    /**
     * It costs no join: prior_sessions is on the candidate row itself.
     *
     * A step that asked for one would put the session or visitor window into
     * every build that has this column, for a value already present.
     */
    public function testItNeedsNoJoin(): void
    {
        $this->assertSame([], (new NewVsReturningStep('new_vs_returning'))->requires());
    }
}
